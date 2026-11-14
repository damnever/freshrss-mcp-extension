<?php
declare(strict_types=1);

/**
 * The token that says “and now what comes after this entry”.
 *
 * FreshRSS pages from the key of the last entry of the page before, so on its own that token is
 * two numbers and anyone could make one up. Signed with a key derived from the installation salt
 * and the account name, it becomes something only this server could have issued, to this account,
 * for this ordering: another account cannot read its way through a queue by guessing at someone
 * else's cursor, and a made-up token is refused here instead of reaching the database as a value
 * that was never checked.
 *
 * The signature is an integrity check, not a session. Nothing is stored, so a token stays good
 * until the entry it names is gone, which is what a queue cursor means: it is a place, not a visit.
 */
final class FreshMcp_Continuation {

	private const PREFIX = 'mcp1';

	/**
	 * The cursor to hand back with the page just sent.
	 */
	public static function make(FreshRSS_Entry $entry, string $sort): string {
		$payload = $sort . '|' . $entry->id() . '|' . ($sort === 'date' ? $entry->date(true) : '-');
		$encoded = self::encode($payload);

		return self::PREFIX . '.' . $encoded . '.' . self::signature($encoded);
	}

	/**
	 * What FreshRSS needs from a token, or null when it is not a token we issued for this
	 * account and this ordering.
	 *
	 * @return array{0: string, 1: list<int>}|null the entry id to resume at, and its sort key
	 */
	public static function parse(string $token, string $sort): ?array {
		$parts = explode('.', $token);
		if (count($parts) !== 3 || $parts[0] !== self::PREFIX || strlen($parts[1]) > 128) {
			return null;
		}
		if (!hash_equals(self::signature($parts[1]), $parts[2])) {
			return null;
		}

		$payload = self::decode($parts[1]);
		if ($payload === null) {
			return null;
		}
		$fields = explode('|', $payload);
		if (count($fields) !== 3 || $fields[0] !== $sort || !ctype_digit($fields[1])) {
			return null;
		}

		$values = [];
		if ($sort === 'date') {
			if (!ctype_digit($fields[2])) {
				return null;
			}
			$values[] = (int) $fields[2];
		}

		return [$fields[1], $values];
	}

	private static function signature(string $encoded): string {
		return hash_hmac('sha256', $encoded, self::key());
	}

	/**
	 * A key that differs per installation and per account, and never leaves the server.
	 *
	 * Binding it to the account is what stops a cursor from one user being used by another:
	 * the signature simply does not verify, and no row of someone else's queue is looked at.
	 */
	private static function key(): string {
		$salt = FreshRSS_Context::hasSystemConf() ? (string) FreshRSS_Context::systemConf()->salt : '';
		$user = Minz_User::name() ?? '';

		return hash('sha256', $salt . '|' . $user . '|freshrss-mcp-continuation', true);
	}

	private static function encode(string $payload): string {
		return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
	}

	private static function decode(string $encoded): ?string {
		$decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

		return $decoded === false || strlen($decoded) > 128 ? null : $decoded;
	}
}
