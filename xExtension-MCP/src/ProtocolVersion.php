<?php
declare(strict_types=1);

/**
 * Which revision of the protocol one request is speaking.
 *
 * Decided per request and forgotten straight afterwards. The modern protocol
 * keeps no session, so a server answering both eras has to work out what it is
 * being asked for from the request in front of it and nothing else, which suits
 * a PHP endpoint where every request starts from zero anyway.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning
 */
final class FreshMcp_ProtocolVersion {

	/** The `_meta` keys the protocol reserves for itself. */
	public const META_PREFIX = 'io.modelcontextprotocol/';
	public const META_PROTOCOL_VERSION = self::META_PREFIX . 'protocolVersion';
	public const META_CLIENT_INFO = self::META_PREFIX . 'clientInfo';
	public const META_CLIENT_CAPABILITIES = self::META_PREFIX . 'clientCapabilities';
	public const META_SERVER_INFO = self::META_PREFIX . 'serverInfo';

	/** Revisions this server answers, newest first. */
	public const SUPPORTED = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

	public const LATEST = self::SUPPORTED[0];

	/** From this revision on, requests carry their own metadata and there is no handshake. */
	private const MODERN_FROM = '2026-07-28';

	/**
	 * What a request naming no version at all is served under. Revisions before
	 * 2025-06-18 define no `MCP-Protocol-Version` header, so the specification
	 * lets a server that still serves those clients read a missing header as
	 * 2025-03-26. That is the door the oldest clients walk through.
	 */
	private const UNNAMED = '2025-03-26';

	public function __construct(public readonly string $value) {
	}

	/** Whether this revision carries its version, identity and capabilities per request. */
	public function isModern(): bool {
		return $this->value >= self::MODERN_FROM;
	}

	public static function isSupported(string $value): bool {
		return in_array($value, self::SUPPORTED, true);
	}

	/**
	 * Work out the revision a request wants, or the error to answer with.
	 *
	 * @param array<string,mixed> $params the params of the request
	 * @param array<string,mixed> $meta params._meta of the request
	 * @return array{0: ?self, 1: ?FreshMcp_ProtocolError} either the version or the refusal
	 */
	public static function negotiate(array $params, array $meta, string $headerVersion): array {
		$inMeta = self::text($meta[self::META_PROTOCOL_VERSION] ?? null);
		$inParams = self::text($params['protocolVersion'] ?? null);

		if ($inMeta !== null && $headerVersion !== '' && $inMeta !== $headerVersion) {
			return [null, FreshMcp_ProtocolError::headerMismatch('MCP-Protocol-Version says ' . $headerVersion .
				' but params._meta carries ' . $inMeta . '.')];
		}

		$asked = $inMeta ?? ($headerVersion !== '' ? $headerVersion : $inParams);
		if ($asked === null) {
			// Nothing to go by, and only the handshake era can be described fully
			// without a stated version, so that is what this request gets.
			return [new self(self::UNNAMED), null];
		}
		if (!self::isSupported($asked)) {
			return [null, FreshMcp_ProtocolError::unsupportedVersion('Unsupported protocol version',
				self::SUPPORTED, $asked)];
		}

		if ($asked >= self::MODERN_FROM) {
			if ($headerVersion === '') {
				return [null, FreshMcp_ProtocolError::headerMismatch('Missing MCP-Protocol-Version header, which ' .
					$asked . ' requires on every request.')];
			}
			if ($inMeta === null) {
				return [null, FreshMcp_ProtocolError::headerMismatch('MCP-Protocol-Version says ' . $asked .
					' but params._meta does not carry ' . self::META_PROTOCOL_VERSION . '.')];
			}
		}
		return [new self($asked), null];
	}

	/**
	 * The `_meta` fields a modern request has to carry. Of the three protocol
	 * fields, clientInfo is the one the specification leaves optional.
	 *
	 * @param array<string,mixed> $meta
	 */
	public function checkRequestMeta(array $meta): ?FreshMcp_ProtocolError {
		if (!$this->isModern()) {
			return null;
		}
		if (!isset($meta[self::META_CLIENT_CAPABILITIES]) || !is_array($meta[self::META_CLIENT_CAPABILITIES])) {
			return FreshMcp_ProtocolError::invalidParams('params._meta must carry ' .
				self::META_CLIENT_CAPABILITIES . '.');
		}
		return null;
	}

	/**
	 * Say that a result is the whole answer, and who sent it. Both are how a
	 * stateless modern server identifies itself without any prior state.
	 *
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $serverInfo
	 * @return array<string,mixed>
	 */
	public function decorate(array $result, array $serverInfo): array {
		if (!$this->isModern()) {
			return $result;
		}
		$meta = $result['_meta'] ?? [];
		if (!is_array($meta)) {
			$meta = [];
		}
		$meta[self::META_SERVER_INFO] ??= $serverInfo;
		return ['resultType' => 'complete'] + $result + ['_meta' => $meta];
	}

	private static function text(mixed $value): ?string {
		return is_string($value) && $value !== '' ? $value : null;
	}
}
