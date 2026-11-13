<?php
declare(strict_types=1);

/**
 * The MCP endpoint: JSON-RPC 2.0 over HTTP, answering two eras of the protocol.
 *
 * - A legacy client (2025-11-25 and earlier) opens with `initialize`, and the
 *   revision agreed there is what it works with afterwards.
 * - A modern client (2026-07-28 on) has no handshake at all: every request names
 *   its own version, identity and capabilities in `params._meta`, mirrors method
 *   and tool name into HTTP headers, and expects each result to say that it is
 *   the complete answer. `server/discover` is what it asks instead of `initialize`.
 *
 * The era comes from the request in front of us and is remembered for nothing:
 * a client may change its mind between two requests, and the modern protocol
 * keeps no server-side session, which suits a PHP endpoint that starts from zero
 * every time anyway. Both eras get the same five read-only tools.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http
 */
final class FreshMcp_Server {

	private const NAME = 'freshrss-mcp';

	/** How long a client may re-use what we told it about our tools, in ms. */
	private const CATALOGUE_TTL_MS = 3600000;

	/** Guidance, put where each era reads it from. */
	private const INSTRUCTIONS = 'Read-only access to a FreshRSS account. list_feeds gives the catalogue and the unread '
		. 'counts, get_unread gives a triage queue that feed_id or category_id can narrow, search takes FreshRSS query '
		. 'syntax, and get_article returns the text of one entry. Nothing here changes FreshRSS: marking items read or '
		. 'starring them stays with the user.';

	/** The id of the request being served, so that an error can be answered with it. */
	private static string|int|null $id = null;

	/**
	 * Handle one request. Always answers, and always finishes the request here so
	 * that nothing of the FreshRSS page around the hook is added to the reply.
	 */
	public static function serve(): never {
		// A PHP notice must never leak into the middle of a JSON body.
		ini_set('display_errors', '0');
		try {
			self::servePost();
		} catch (FreshMcp_ProtocolError $error) {
			$error->withId(self::$id)->respond();
		} catch (Throwable $throwable) {	// @phpstan-ignore catch.neverThrown
			// Nothing here throws today; the net stays so that a warning turned into an
			// exception by a custom handler cannot reach the client as HTML.
			Minz_Log::warning('MCP: ' . $throwable::class . ': ' . $throwable->getMessage());
			FreshMcp_ProtocolError::internal('Internal error; see the FreshRSS logs.')->withId(self::$id)->respond();
		}
		exit();
	}

	/**
	 * @throws FreshMcp_ProtocolError whatever the request got wrong, ready to be answered
	 */
	private static function servePost(): void {
		if (FreshMcp_Http::method() !== 'POST') {
			// This covers the standalone GET stream and the session-terminating DELETE,
			// both of which 2026-07-28 removed.
			FreshMcp_Http::send('', 405, ['Allow: POST']);
			return;
		}
		if (FreshMcp_Auth::authenticate() === null) {
			self::unauthorized();
			return;
		}

		[$body, $reason] = FreshMcp_Http::readBody();
		if ($reason !== null) {
			FreshMcp_ProtocolError::parse($reason)->respond();
			return;
		}
		if (!is_array($body) || array_is_list($body)) {
			throw FreshMcp_ProtocolError::invalidRequest('Send one JSON-RPC 2.0 object at a time: no batches, and '
				. 'never a response.');
		}

		self::$id = self::readId($body);
		$method = $body['method'] ?? null;
		if (($body['jsonrpc'] ?? null) !== '2.0') {
			throw FreshMcp_ProtocolError::invalidRequest('Not a JSON-RPC 2.0 request: jsonrpc must be "2.0".');
		}
		if (!is_string($method) || $method === '') {
			throw FreshMcp_ProtocolError::invalidRequest('Missing method.');
		}
		$params = $body['params'] ?? [];
		if (!is_array($params)) {
			throw FreshMcp_ProtocolError::invalidParams('params must be an object.');
		}
		$meta = $params['_meta'] ?? [];
		if (!is_array($meta)) {
			throw FreshMcp_ProtocolError::invalidParams('_meta must be an object.');
		}

		[$version, $error] = FreshMcp_ProtocolVersion::negotiate($params, $meta,
			FreshMcp_Http::header('MCP-Protocol-Version'));
		if ($version === null || $error !== null) {
			throw $error ?? FreshMcp_ProtocolError::invalidRequest('Could not agree a protocol version.');
		}

		if (str_starts_with($method, 'notifications/')) {
			// Acknowledged by an empty 202 and never by a result. This revision defines
			// no header requirements for a notification, so nothing is checked here.
			FreshMcp_Http::send('', 202);
			return;
		}

		if ($version->isModern()) {
			// A modern request mirrors parts of its body into headers, and the two
			// sources have to agree: a gateway may route on the header while we act
			// on the body, and that difference is a security problem, not a curiosity.
			self::checkHeaders($method, $params);
			$metaError = $version->checkRequestMeta($meta);
			if ($metaError !== null) {
				throw $metaError;
			}
		}

		$result = self::dispatch($method, $params, $version);
		if (is_array($result)) {
			$result = $version->decorate($result, self::identity());
		}
		FreshMcp_Http::sendJson(FreshMcp_Http::envelope($result, self::$id));
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>|stdClass
	 */
	private static function dispatch(string $method, array $params, FreshMcp_ProtocolVersion $version): array|stdClass {
		$modern = $version->isModern();
		switch ($method) {
			case 'server/discover':
				if (!$modern) {
					// No such method before 2026-07-28, where `initialize` did this job.
					throw FreshMcp_ProtocolError::methodNotFound($method);
				}
				return self::discoverResult();
			case 'initialize':
				if ($modern) {
					// Name the versions we speak: a legacy client cannot fall forward, and
					// this may be the only thing its user ever sees.
					throw FreshMcp_ProtocolError::methodNotFound($method, true)->withData([
						'hint' => 'The handshake was removed in 2026-07-28. Call server/discover, or just send the request '
							. 'with params._meta.',
						'supported' => FreshMcp_ProtocolVersion::SUPPORTED,
					]);
				}
				return self::initializeResult($params, $version);
			case 'ping':
				if ($modern) {
					throw FreshMcp_ProtocolError::methodNotFound($method, true)->withData([
						'hint' => 'ping was removed in 2026-07-28; any 2xx answer from this endpoint is the liveness signal.',
					]);
				}
				// An empty object and not an empty list: a result is an object.
				return new stdClass();
			case 'tools/list':
				$tools = ['tools' => FreshMcp_Tools::definitions()];
				return $modern ? $tools + ['ttlMs' => self::CATALOGUE_TTL_MS, 'cacheScope' => 'public'] : $tools;
			case 'tools/call':
				return self::callTool($params);
			default:
				throw FreshMcp_ProtocolError::methodNotFound($method, $modern);
		}
	}

	/**
	 * `server/discover`: what we speak, what we can do, and who we are.
	 *
	 * @return array<string,mixed>
	 */
	private static function discoverResult(): array {
		return [
			'supportedVersions' => FreshMcp_ProtocolVersion::SUPPORTED,
			'capabilities' => ['tools' => ['listChanged' => false]],
			'_meta' => [FreshMcp_ProtocolVersion::META_SERVER_INFO => self::identity()],
			'instructions' => self::INSTRUCTIONS,
			'ttlMs' => self::CATALOGUE_TTL_MS,
			'cacheScope' => 'public',
		];
	}

	/**
	 * `initialize`, for a client that still opens with a handshake.
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private static function initializeResult(array $params, FreshMcp_ProtocolVersion $version): array {
		$client = is_array($params['clientInfo'] ?? null) ? $params['clientInfo'] : [];
		Minz_Log::debug('MCP: initialize from ' . strval($client['name'] ?? 'an unnamed client') . ' under '
			. $version->value);
		return [
			'protocolVersion' => $version->value,
			'capabilities' => ['tools' => ['listChanged' => false]],
			'serverInfo' => self::identity(),
			'instructions' => self::INSTRUCTIONS,
		];
	}

	/**
	 * `tools/call`. A tool that could not do the job reports that inside the result,
	 * so that the model reads the message instead of a transport error.
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private static function callTool(array $params): array {
		$name = $params['name'] ?? null;
		if (!is_string($name) || $name === '') {
			throw FreshMcp_ProtocolError::invalidParams('No tool name given.');
		}
		if (!FreshMcp_Tools::exists($name)) {
			throw FreshMcp_ProtocolError::invalidParams("Unknown tool: {$name}");
		}
		$arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
		return FreshMcp_Tools::call($name, $arguments);
	}

	/**
	 * The Streamable HTTP headers that mirror the body, and must agree with it.
	 * No `Mcp-Param-*` header is checked, because no parameter of these tools is
	 * annotated with `x-mcp-header`, so a client has nothing to mirror that way.
	 *
	 * @param array<string,mixed> $params
	 */
	private static function checkHeaders(string $method, array $params): void {
		$declared = FreshMcp_Http::header('Mcp-Method');
		if ($declared !== $method) {
			throw FreshMcp_ProtocolError::headerMismatch($declared === '' ?
				"Missing Mcp-Method header, which this transport requires; the body asks for {$method}." :
				"Mcp-Method says {$declared} but the body asks for {$method}.");
		}

		$name = self::mirroredName($method, $params);
		if ($name === null) {
			return;
		}
		$declared = FreshMcp_Http::header('Mcp-Name');
		if ($declared === '') {
			throw FreshMcp_ProtocolError::headerMismatch('Missing Mcp-Name header, which this transport requires for '
				. $method . '; the body says ' . ($name === '' ? 'nothing.' : "{$name}."));
		}
		$decoded = self::decodeHeaderValue($declared);
		if ($decoded !== $name) {
			throw FreshMcp_ProtocolError::headerMismatch('Mcp-Name header value ' . var_export($decoded, true)
				. ' does not match the body value ' . var_export($name, true) . '.');
		}
	}

	/**
	 * What `Mcp-Name` has to carry: `params.name` for a tool call, `params.uri` for
	 * a resource read, or null when the method has no such field at all.
	 *
	 * @param array<string,mixed> $params
	 */
	private static function mirroredName(string $method, array $params): ?string {
		if (!in_array($method, ['tools/call', 'prompts/get', 'resources/read'], true)) {
			return null;
		}
		$value = $params[$method === 'resources/read' ? 'uri' : 'name'] ?? null;
		return is_string($value) ? $value : '';
	}

	/**
	 * Undo the `=?base64?…?=` sentinel a client uses for a value that is not plain
	 * ASCII. A sentinel that does not decode is left alone, so that it fails to match.
	 */
	private static function decodeHeaderValue(string $value): string {
		if (!str_starts_with($value, '=?base64?') || !str_ends_with($value, '?=')) {
			return $value;
		}
		$decoded = base64_decode(substr($value, 9, -2), true);
		return $decoded === false ? $value : $decoded;
	}

	/**
	 * Who is answering.
	 *
	 * @return array<string,mixed>
	 */
	private static function identity(): array {
		return [
			'name' => self::NAME,
			'title' => 'FreshRSS',
			'version' => MCPExtension::VERSION,
			'description' => 'Read-only Model Context Protocol server for FreshRSS feed triage.',
			'websiteUrl' => 'https://github.com/samteezy/freshrss-mcp-extension',
		];
	}

	private static function unauthorized(): void {
		FreshMcp_Http::sendJson([
			'error' => 'Missing or invalid credentials. Send the FreshRSS API password as Authorization: Basic '
				. 'base64("<user>:<api-password>"), or as Authorization: Bearer <user>/<api-password>.',
		], 401, ['WWW-Authenticate: Basic realm="FreshRSS"']);
	}

	/**
	 * @param array<string,mixed> $request
	 */
	private static function readId(array $request): string|int|null {
		$id = $request['id'] ?? null;
		return is_string($id) || is_int($id) ? $id : null;
	}
}
