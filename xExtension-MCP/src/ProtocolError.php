<?php
declare(strict_types=1);

/**
 * A protocol-level failure, reported as a JSON-RPC error object.
 * Tool failures are different: those are reported inside a successful result.
 */
final class FreshMcp_ProtocolError extends Exception {
	public function __construct(int $code, string $message) {
		parent::__construct($message, $code);
	}
}
