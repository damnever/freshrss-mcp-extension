<?php
declare(strict_types=1);

/**
 * Whether this endpoint may fetch a URL on the caller's behalf.
 *
 * get_article(fetch_full_content) is the one place where the server makes an outbound request,
 * and the URL it fetches comes from a feed rather than from us: a publisher can advertise any
 * link it likes, and on a home network the interesting hosts are private by definition. So a
 * page is fetched only when every address its host resolves to is a public one, which keeps
 * this endpoint out of the LAN, of the loopback and of the cloud metadata addresses.
 *
 * What this cannot cover, from inside an extension: the request itself is made by FreshRSS,
 * which follows redirections and resolves the host name again, so a host that is public when
 * checked here can still be pointed elsewhere before FreshRSS connects. Anything that must
 * never be reachable this way belongs on a different network, not merely on another port.
 */
final class FreshMcp_UrlPolicy {

	/** Ranges this endpoint never connects to, as [network, prefix length]. */
	private const BLOCKED_V4 = [
		['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
		['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
		['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
	];

	private const BLOCKED_V6 = [
		['::', 128], ['::1', 128], ['::ffff:0:0', 96], ['64:ff9b::', 96], ['100::', 64],
		['2001::', 32], ['2001:2::', 48], ['2002::', 16], ['2001:db8::', 32], ['fc00::', 7],
		['fe80::', 10], ['ff00::', 8],
	];

	/**
	 * Why that URL may not be fetched, or null when it may.
	 *
	 * The message is written for the model that asked, so it says what to do instead.
	 */
	public static function refusal(string $url): ?string {
		$parts = parse_url($url);
		if (!is_array($parts)) {
			return 'the article link is not a valid URL, so it cannot be fetched';
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		if ($scheme !== 'http' && $scheme !== 'https') {
			return 'only http and https article links can be fetched' .
				($scheme === '' ? ', and this one names no scheme' : ', not ' . $scheme);
		}
		if (($parts['user'] ?? '') !== '' || ($parts['pass'] ?? '') !== '') {
			return 'the article link carries credentials of its own, and those are not sent anywhere';
		}

		$host = strtolower(trim((string) ($parts['host'] ?? '')));
		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			$host = substr($host, 1, -1);
		}
		if ($host === '') {
			return 'the article link names no host, so there is nothing to fetch';
		}

		$addresses = self::addresses($host);
		if ($addresses === []) {
			return 'the host of the article link does not resolve to any address';
		}
		foreach ($addresses as $address) {
			if (!self::isPublic($address)) {
				return 'the article link resolves to ' . $address . ', which is not a public address, so it is not '
					. 'fetched; leave fetch_full_content off and use the text the feed already sent.';
			}
		}

		return null;
	}

	/**
	 * Every address a host resolves to, or the host itself when it is already one.
	 *
	 * @return list<string>
	 */
	private static function addresses(string $host): array {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return [$host];
		}

		$found = gethostbynamel($host);
		$addresses = is_array($found) ? $found : [];
		$aaaa = dns_get_record($host, DNS_AAAA);
		foreach (is_array($aaaa) ? $aaaa : [] as $record) {
			if (is_string($record['ipv6'] ?? null)) {
				$addresses[] = $record['ipv6'];
			}
		}

		return $addresses;
	}

	/**
	 * Whether an address is somewhere this endpoint may connect.
	 */
	public static function isPublic(string $ip): bool {
		$packed = @inet_pton($ip);
		if (!is_string($packed)) {
			return false;
		}

		return !self::inAny($packed, strlen($packed) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6);
	}

	/**
	 * @param list<array{0: string, 1: int}> $blocks
	 */
	private static function inAny(string $packed, array $blocks): bool {
		foreach ($blocks as $block) {
			if (self::inBlock($packed, $block[0], $block[1])) {
				return true;
			}
		}

		return false;
	}

	private static function inBlock(string $packed, string $network, int $bits): bool {
		$raw = @inet_pton($network);
		$width = strlen($packed);
		if (!is_string($raw) || strlen($raw) !== $width || $bits < 0 || $bits > $width * 8) {
			return false;
		}

		$whole = intdiv($bits, 8);
		if ($whole > 0 && strncmp($packed, $raw, $whole) !== 0) {
			return false;
		}

		$rest = $bits % 8;
		if ($rest === 0 || $whole === $width) {
			return true;
		}

		$mask = 0xFF << (8 - $rest) & 0xFF;

		return (ord($packed[$whole]) & $mask) === (ord($raw[$whole]) & $mask);
	}
}
