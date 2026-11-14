<?php
declare(strict_types=1);

/**
 * A protocol-level failure, on its way out as a JSON-RPC 2.0 error response.
 *
 * A tool that could not do its job does not report through this class: that
 * belongs inside a successful tool result, so the model reads the message and
 * can correct itself. Only the protocol itself failing becomes an error object.
 *
 * The HTTP status travels with the JSON-RPC code because the modern transport
 * gives some codes part of their meaning through the status: 400 for a header
 * mismatch or an unusable protocol version, 404 for a method that does not exist.
 */
final class FreshMcp_ProtocolError extends Exception implements JsonSerializable {

	public const PARSE_ERROR = -32700;
	public const INVALID_REQUEST = -32600;
	public const METHOD_NOT_FOUND = -32601;
	public const INVALID_PARAMS = -32602;
	public const INTERNAL_ERROR = -32603;

	/** The mirrored HTTP headers contradict the request body. */
	public const HEADER_MISMATCH = -32020;
	/** The client did not declare a capability the request needs. */
	public const MISSING_CLIENT_CAPABILITY = -32021;
	/** The client asked for a protocol revision this server does not speak. */
	public const UNSUPPORTED_PROTOCOL_VERSION = -32022;

	private int $status = 200;

	private string|int|null $id = null;

	/** @var array<string,mixed>|null */
	private ?array $data = null;

	private function __construct(int $code, string $message, int $status = 200) {
		parent::__construct($message, $code);
		$this->status = $status;
	}

	public static function parse(string $message): self {
		return new self(self::PARSE_ERROR, $message, 400);
	}

	public static function invalidRequest(string $message): self {
		return new self(self::INVALID_REQUEST, $message, 400);
	}

	public static function invalidParams(string $message): self {
		return new self(self::INVALID_PARAMS, $message, 400);
	}

	/**
	 * An unknown method is a 404 over the modern transport, and a plain JSON-RPC
	 * error over the legacy one where the status code carries no protocol meaning.
	 */
	public static function methodNotFound(string $method, bool $modern = false): self {
		return new self(self::METHOD_NOT_FOUND, 'Unknown method: ' . $method, $modern ? 404 : 200);
	}

	public static function headerMismatch(string $message): self {
		return new self(self::HEADER_MISMATCH, $message, 400);
	}

	/**
	 * @param string[] $supported revisions this server does speak, for the client to retry with
	 */
	public static function unsupportedVersion(string $message, array $supported, string $requested): self {
		$error = new self(self::UNSUPPORTED_PROTOCOL_VERSION, $message, 400);
		return $error->withData(['supported' => $supported, 'requested' => $requested]);
	}

	/** The client will not accept the only media type this endpoint answers in. */
	public static function notAcceptable(string $message): self {
		return new self(self::INVALID_REQUEST, $message, 406);
	}

	public static function internal(string $message): self {
		return new self(self::INTERNAL_ERROR, $message, 500);
	}

	/**
	 * Say which request this is the answer to, and carry on chaining. A Throwable
	 * cannot be cloned in PHP, so this is the same object, which is all it needs to
	 * be: one error answers one request, once.
	 */
	public function withId(string|int|null $id): self {
		$this->id = $id;
		return $this;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function withData(array $data): self {
		$this->data = $data;
		return $this;
	}

	public function status(): int {
		return $this->status;
	}

	/**
	 * Write the answer, which is all the object exists for.
	 */
	public function respond(): void {
		FreshMcp_Http::sendJson(['jsonrpc' => '2.0', 'id' => $this->id, 'error' => $this], $this->status);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		$error = ['code' => $this->getCode(), 'message' => $this->getMessage()];
		if ($this->data !== null) {
			$error['data'] = $this->data;
		}
		return $error;
	}
}
