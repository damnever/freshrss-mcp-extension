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
		return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
	}

	/**
	 * One request header, or '' when it is absent. Header names are
	 * case-insensitive, so they are all lower-cased once here.
	 */
	public static function header(string $name): string {
		return self::headers()[strtolower($name)] ?? '';
	}

	/**
	 * Read and decode the request body.
	 *
	 * @return array{0: mixed, 1: ?string} what was sent, and why it could not be read
	 */
	public static function readBody(): array {
		$raw = file_get_contents('php://input');
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
				if (str_starts_with((string) $key, 'HTTP_')) {
					$found[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = trim((string) $value);
				}
			}
			self::$headers = $found;
		}
		return self::$headers;
	}
}
