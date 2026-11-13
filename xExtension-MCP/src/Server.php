<?php
declare(strict_types=1);

/**
 * A minimal Model Context Protocol server: JSON-RPC 2.0 over a single HTTP endpoint.
 *
 * Only what a read-only tool server needs is implemented: `initialize`, `ping`,
 * `tools/list` and `tools/call`. Replies are always `application/json`; the optional
 * per-request SSE stream is unused and the server is stateless, which suits PHP's
 * one-request-per-process model.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http
 */
final class FreshMcp_Server {
	/** Protocol revisions this server is known to interoperate with, newest first. */
	private const SUPPORTED_PROTOCOL_VERSIONS = ['2026-07-28', '2025-06-18', '2025-03-26', '2024-11-05'];

	private const LATEST_PROTOCOL_VERSION = '2026-07-28';

	// Predefined JSON-RPC 2.0 error codes.
	private const PARSE_ERROR = -32700;
	private const INVALID_REQUEST = -32600;
	private const METHOD_NOT_FOUND = -32601;
	private const INVALID_PARAMS = -32602;
	private const INTERNAL_ERROR = -32603;

	private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

	/**
	 * Handle one request. Always terminates the process.
	 */
	public static function serve(): never {
		// A PHP notice must never leak into the middle of a JSON body.
		ini_set('display_errors', '0');

		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			header('Allow: POST');
			self::send(405, "Only POST is accepted. Register this URL as a remote (Streamable HTTP) MCP server.\n");
		}

		if (FreshMcp_Auth::authenticate() === null) {
			self::unauthorized();
		}

		$raw = file_get_contents('php://input');
		$request = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
		if (!is_array($request)) {
			self::sendError(null, self::PARSE_ERROR, 'The request body is not valid JSON.');
		}

		$method = $request['method'] ?? null;
		if (($request['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
			self::sendError(self::id($request), self::INVALID_REQUEST, 'Not a JSON-RPC 2.0 request.');
		}

		$params = is_array($request['params'] ?? null) ? $request['params'] : [];

		if (str_starts_with($method, 'notifications/')) {
			// Acknowledged by an empty 202, never by a result. e.g. notifications/initialized.
			self::send(202, '');
		}

		try {
			$result = match ($method) {
				'initialize' => self::initialize($params),
				'ping' => new stdClass(),
				'tools/list' => ['tools' => FreshMcp_Tools::definitions()],
				'tools/call' => self::callTool($params),
				default => throw new FreshMcp_ProtocolError(self::METHOD_NOT_FOUND, "Unsupported method: {$method}"),
			};
		} catch (FreshMcp_ProtocolError $e) {
			// An exception without a code of its own is an internal error.
			self::sendError(self::id($request), $e->getCode() === 0 ? self::INTERNAL_ERROR : $e->getCode(), $e->getMessage());
		} catch (Throwable $e) {	// @phpstan-ignore catch.neverThrown
			// Nothing in the block throws today; this net stays so that a warning turned into an
			// exception by a custom handler cannot reach the client as HTML.
			Minz_Log::warning('MCP: ' . $e::class . ': ' . $e->getMessage());
			self::sendError(self::id($request), self::INTERNAL_ERROR, 'Internal error; see the FreshRSS logs.');
		}

		self::sendResult(self::id($request), $result);
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private static function initialize(array $params): array {
		$requested = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';

		return [
			// Keep the client's revision when we know it, otherwise offer our newest one.
			'protocolVersion' => in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true) ? $requested : self::LATEST_PROTOCOL_VERSION,
			'capabilities' => ['tools' => ['listChanged' => false]],
			'serverInfo' => [
				'name' => 'freshrss-mcp',
				'title' => 'FreshRSS',
				'version' => MCPExtension::VERSION,
			],
			'instructions' => 'Read-only access to a FreshRSS account. list_feeds gives the catalogue and unread counts, '
				. 'get_unread gives a triage queue that feed_id or category_id can narrow, search takes FreshRSS query syntax, '
				. 'and get_article returns the text of one entry. Nothing here changes FreshRSS: marking items read or starring '
				. 'them stays with the user.',
		];
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private static function callTool(array $params): array {
		$name = is_string($params['name'] ?? null) ? $params['name'] : '';
		if ($name === '' || !FreshMcp_Tools::exists($name)) {
			throw new FreshMcp_ProtocolError(self::INVALID_PARAMS, $name === '' ? 'No tool name given.' : "Unknown tool: {$name}");
		}
		$arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

		return FreshMcp_Tools::call($name, $arguments);
	}

	/** @return int|string|null */
	private static function id(array $request): int|string|null {
		$id = $request['id'] ?? null;
		return is_int($id) || is_string($id) ? $id : null;
	}

	private static function unauthorized(): never {
		header('WWW-Authenticate: Bearer realm="FreshRSS"');
		self::send(401, "Missing or invalid credentials. Send the FreshRSS API password as\n"
			. "'Authorization: Basic \" . base64(\"<user>:<api-password>\")', or as\n"
			. "'Authorization: Bearer <user>/<api-password>'.\n");
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private static function sendResult(int|string|null $id, array|stdClass $result): never {
		self::send(200, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], self::JSON_FLAGS));
	}

	private static function sendError(int|string|null $id, int $code, string $message): never {
		self::send(200, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], self::JSON_FLAGS));
	}

	private static function send(int $status, string $body): never {
		http_response_code($status);
		header('Content-Type: application/json; charset=UTF-8');
		header('Cache-Control: no-store');
		if ($body !== '') {
			echo $body;
		}
		exit();
	}
}
