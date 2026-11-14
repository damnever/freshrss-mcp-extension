<?php
declare(strict_types=1);

/**
 * Authenticates MCP requests with a FreshRSS API password, then loads that user's context.
 *
 * p/api/misc.php does not authenticate anything, so this is the only thing standing between the
 * network and the account's feeds. Accepted forms, one of them per request:
 *
 *   Authorization: Basic base64("<user>:<api-password>")
 *   Authorization: Bearer <user>/<api-password>
 *   Authorization: GoogleLogin auth=<user>/<token>
 *   Authorization: Token <user>/<token>
 *   X-Api-Key: <user>/<token>
 *
 * <secret> is either the API password itself or the `Auth` token that the Google Reader API
 * returns from clientLogin. Prefer the token: unlike the password it cannot be used to sign in to
 * the web interface, and it is what the mobile apps already hold. Both are secrets in transit, so
 * serve FreshRSS over TLS.
 *
 * Two things are deliberate:
 *
 * - The account name travels in the header, never in the URL. Request lines end up in access logs,
 *   and a `?user=` parameter would put the account name of every MCP call in them for no gain.
 * - Credentials are taken verbatim. Nothing is percent-decoded, nothing is trimmed and nothing with
 *   whitespace or a control character in it is accepted, so there is exactly one spelling of every
 *   credential. Percent-decoding first would make `%74est` and `test` the same account and let a
 *   leading space stand for a password, which is how credential checking starts to mean whatever the
 *   transport in front happened to do to the bytes.
 */
final class FreshMcp_Auth {

	/**
	 * The FreshRSS username whose request this is, or null when it is not authenticated.
	 *
	 * Never throws, and never distinguishes one refusal from another to the caller: a name that
	 * does not exist is refused exactly like a right name with a wrong password, because an
	 * endpoint that answers 401 to one and 500 to the other is a list of the accounts.
	 */
	public static function authenticate(): ?string {
		try {
			return self::check();
		} catch (Throwable $throwable) {
			Minz_Log::warning('MCP: could not finish authenticating the request: ' . $throwable::class .
				': ' . $throwable->getMessage());
			return null;
		}
	}

	/**
	 * Look the account up and check its secret.
	 *
	 * @throws Throwable the FreshRSS context layer is free to raise whatever it likes and documents
	 *         none of it, so a refusal may arrive as an exception instead of as null and the caller
	 *         has to be ready for either. That is what the catch in authenticate() is for.
	 */
	private static function check(): ?string {
		$credentials = self::credentials();
		if ($credentials === null) {
			return null;
		}
		[$user, $secret] = $credentials;

		if ($user === Minz_User::INTERNAL_USER || FreshRSS_user_Controller::checkUsername($user) !== true) {
			Minz_Log::warning('MCP: an API request named an account that is not a FreshRSS user name');
			return null;
		}
		if (!FreshRSS_UserDAO::exists($user)) {
			// Checked before anything is loaded for it, so that an absent account costs the same
			// answer as a wrong password.
			Minz_Log::warning('MCP: an API request named the account ' . $user . ', which does not exist');
			return null;
		}

		FreshRSS_Context::initUser($user);
		$conf = FreshRSS_Context::userConf();
		// Note the bare `!`: empty()/isset() on a configuration property consults __isset(), which
		// only knows keys stored in this user's own file, and `enabled` usually comes from the defaults.
		if (!FreshRSS_Context::hasUserConf() || !FreshRSS_Context::hasSystemConf() || !$conf->enabled) {
			Minz_Log::warning('MCP: no usable configuration for the account ' . $user);
			return null;
		}

		$hash = (string) $conf->apiPasswordHash;
		if ($hash === '') {
			Minz_Log::warning('MCP: no API password set for the account ' . $user);
			return null;
		}

		// Same derivation as p/api/greader.php, so one credential serves both APIs.
		$token = sha1(FreshRSS_Context::systemConf()->salt . $user . $hash);
		if (!hash_equals($token, $secret) && !password_verify($secret, $hash)) {
			Minz_Log::warning('MCP: rejected the API credentials of the account ' . $user);
			return null;
		}

		return $user;
	}

	/**
	 * The pair from the request headers, if there is a usable one.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function credentials(): ?array {
		foreach (['Authorization', 'X-Api-Key'] as $header) {
			$value = trim(self::header($header));
			if ($value === '') {
				continue;
			}

			if (stripos($value, 'Basic ') === 0) {
				$decoded = base64_decode(substr($value, 6), true);
				if ($decoded === false || !str_contains($decoded, ':')) {
					continue;
				}
				[$user, $secret] = explode(':', $decoded, 2);
				return self::pair($user, $secret);
			}

			foreach (['Bearer ', 'GoogleLogin auth=', 'Token '] as $scheme) {
				if (stripos($value, $scheme) === 0) {
					$value = substr($value, strlen($scheme));
					break;
				}
			}
			if (str_contains($value, '/')) {
				[$user, $secret] = explode('/', $value, 2);
				return self::pair($user, $secret);
			}

			// A secret on its own names no account, and the URL is not where the account goes.
			Minz_Log::warning('MCP: an API request carried a secret without an account name');
		}

		return null;
	}

	/**
	 * @return array{0: string, 1: string}|null
	 */
	private static function pair(string $user, string $secret): ?array {
		return self::verbatim($user) && self::verbatim($secret) ? [$user, $secret] : null;
	}

	/**
	 * Whether this credential is in the one spelling it is accepted in.
	 */
	private static function verbatim(string $value): bool {
		return $value !== '' && !str_contains($value, '%') &&
			preg_match('/[\x00-\x1F\x7F\s]/', $value) !== 1;
	}

	/**
	 * Read a request header, including under nginx where getallheaders() is missing.
	 */
	private static function header(string $name): string {
		$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
		if (is_string($_SERVER[$key] ?? null)) {
			return $_SERVER[$key];
		}
		if (is_string($_SERVER['REDIRECT_' . $key] ?? null)) {
			// Stripped by some Apache configurations, e.g. with mod_rewrite.
			return $_SERVER['REDIRECT_' . $key];
		}
		if (function_exists('getallheaders')) {
			foreach (getallheaders() as $known => $value) {
				if (is_string($known) && strcasecmp($known, $name) === 0 && is_string($value)) {
					return $value;
				}
			}
		}

		return '';
	}
}
