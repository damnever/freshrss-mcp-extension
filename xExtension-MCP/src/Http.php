<?php
declare(strict_types=1);

/**
 * The handful of HTTP primitives this endpoint needs.
 *
 * Request and response are both one-shot: FreshRSS hands the request to the
 * extension and the extension owns the answer completely, headers included.
 */
final class FreshMcp_Http {

	/** Refuse absurd bodies before even parsing them. */
	private const MAX_BODY_BYTES = 262144;

	/** @var array<string,string>|null */
	private static ?array $headers = null;

	public static function method(): string {
		$method = $_SERVER['REQUEST_METHOD'] ?? '';

		return is_string($method) ? strtoupper($method) : '';
	}

	/**
	 * One request header, or '' when it is absent. Header names are
	 * case-insensitive, so they are all lower-cased once here.
	 */
	public static function header(string $name): string {
		return self::headers()[strtolower($name)] ?? '';
	}

	/**
	 * Whether the client will take a JSON answer, judged from its Accept header.
	 *
	 * A client that asks only for something this endpoint cannot send, text/event-stream most
	 * often, is told so with 406 rather than handed JSON it said it does not want.
	 */
	public static function acceptsJson(): bool {
		$accept = self::header('accept');
		if ($accept === '') {
			// No Accept header means any media type will do.
			return true;
		}
		foreach (explode(',', $accept) as $range) {
			$parts = explode(';', $range);
			$type = strtolower(trim($parts[0]));
			if ($type === '' || !self::wanted($parts)) {
				continue;
			}
			if ($type === '*/*' || $type === 'application/*' || $type === 'application/json') {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the client still wants a type after its parameters, `q=0` being how it says it does not.
	 *
	 * @param list<string> $params the type and its parameters
	 */
	private static function wanted(array $params): bool {
		foreach (array_slice($params, 1) as $parameter) {
			$parameter = trim($parameter);
			if (stripos($parameter, 'q=') === 0) {
				return (float) substr($parameter, 2) > 0;
			}
		}

		return true;
	}

	/**
	 * Read and decode the request body.
	 *
	 * @return array{0: mixed, 1: ?string} what was sent, and why it could not be read
	 */
	public static function readBody(): array {
		// Read one byte past the limit and no more, so that an oversized body is never held in memory.
		$raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
		if ($raw === false) {
			return [null, 'Could not read the request body.'];
		}
		if (strlen($raw) > self::MAX_BODY_BYTES) {
			return [null, 'Request body is too large.'];
		}
		if (trim($raw) === '') {
			return [null, 'Empty request body.'];
		}
		try {
			return [json_decode($raw, true, 32, JSON_THROW_ON_ERROR), null];
		} catch (JsonException $exception) {
			return [null, 'Could not parse the request body as JSON: ' . $exception->getMessage()];
		}
	}

	/**
	 * @param string[] $headers extra headers, as 'Name: value'
	 */
	public static function send(string $body, int $status = 200, array $headers = []): void {
		http_response_code($status);
		header('Content-Type: application/json; charset=UTF-8');
		// Nothing here is cacheable: it is per-account data behind a password.
		header('Cache-Control: no-store');
		foreach ($headers as $header) {
			header($header);
		}
		if ($status === 204 || $status === 304) {
			// Defined as carrying no body.
			return;
		}
		echo $body;
	}

	/**
	 * @param mixed $payload
	 * @param string[] $headers
	 */
	public static function sendJson(mixed $payload, int $status = 200, array $headers = []): void {
		$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		self::send($body === false ? '{}' : $body, $status, $headers);
	}

	/**
	 * A decoded JSON value as a member-name-keyed array, or null when it is not one.
	 *
	 * json_decode() hands an object over as a PHP array and, because PHP turns a member called `0`
	 * into an integer key, that array is not keyed by strings alone. Nothing downstream should have to
	 * wonder about that, and a message whose member names are not names is not a request.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function object(mixed $value): ?array {
		if (!is_array($value)) {
			return null;
		}
		if ($value === []) {
			// Decoded with the associative flag, `{}` and `[]` are the same empty PHP array, and a request
			// with nothing in its params is ordinary: a ping with no params and a ping with an empty object
			// of one are the same request. Only a list with members is certainly not an object.
			return [];
		}
		if (array_is_list($value)) {
			return null;
		}

		$object = [];
		foreach ($value as $key => $member) {
			$name = is_int($key) ? (string) $key : $key;
			if (!is_string($name)) {
				return null;
			}
			$object[$name] = $member;
		}

		return $object;
	}

	/**
	 * @param array<string,mixed>|stdClass $result
	 * @return array<string,mixed> a JSON-RPC 2.0 result envelope
	 */
	public static function envelope(array|stdClass $result, string|int|null $id): array {
		return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
	}

	/**
	 * @return array<string,string> every request header, names lower-cased
	 */
	private static function headers(): array {
		if (self::$headers === null) {
			$found = [];
			// The web server hands every request header over as HTTP_*, with dashes turned
			// into underscores, and that is the one form every SAPI agrees on. These are the
			// only headers this endpoint reads.
			foreach ($_SERVER as $key => $value) {
				if (!is_scalar($value) || !str_starts_with(strval($key), 'HTTP_')) {
					continue;
				}
				$name = strtolower(str_replace('_', '-', substr(strval($key), 5)));
				$found[$name] = trim(strval($value));
			}
			self::$headers = $found;
		}
		return self::$headers;
	}
}
