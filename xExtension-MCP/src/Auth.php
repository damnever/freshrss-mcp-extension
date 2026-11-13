<?php
declare(strict_types=1);

/**
 * Authenticates MCP requests with a FreshRSS API password, then loads that user's context.
 *
 * p/api/misc.php does not authenticate anything, so this is the only thing standing between
 * the network and the account's feeds. Accepted forms:
 *
 *   Authorization: Basic base64("<user>:<api-password>")
 *   Authorization: Bearer <user>/<secret>
 *   Authorization: GoogleLogin auth=<user>/<secret>
 *   X-Api-Key: <user>/<secret>
 *
 * <secret> is either the API password itself, or the `Auth` token that the Google Reader
 * compatible API returns from clientLogin. Prefer the token: unlike the password it cannot
 * be used to log in to the web interface, and it matches what the mobile apps already hold.
 * Both are secrets in transit, so serve FreshRSS over TLS.
 */
final class FreshMcp_Auth {
	/**
	 * @return string|null the FreshRSS username, or null when the request is not authenticated
	 */
	public static function authenticate(): ?string {
		$credentials = self::credentials();
		if ($credentials === null) {
			return null;
		}
		[$user, $secret] = $credentials;

		if ($user === Minz_User::INTERNAL_USER || !FreshRSS_user_Controller::checkUsername($user)) {
			Minz_Log::warning('MCP: unknown user name in an API request');
			return null;
		}

		FreshRSS_Context::initUser($user);
		$conf = FreshRSS_Context::userConf();
		// Note the bare `!`: empty()/isset() on a configuration property consults __isset(), which
		// only knows keys stored in this user's own file, and `enabled` usually comes from the defaults.
		if (!FreshRSS_Context::hasUserConf() || !FreshRSS_Context::hasSystemConf() || !$conf->enabled) {
			Minz_Log::warning('MCP: no usable configuration for user ' . $user);
			return null;
		}

		$hash = (string) $conf->apiPasswordHash;
		if ($hash === '') {
			Minz_Log::warning('MCP: no API password set for user ' . $user);
			return null;
		}

		// Same derivation as p/api/greader.php, so one credential serves both APIs.
		$token = sha1(FreshRSS_Context::systemConf()->salt . $user . $hash);
		if (!hash_equals($token, $secret) && !password_verify($secret, $hash)) {
			Minz_Log::warning('MCP: rejected the API credentials of user ' . $user);
			return null;
		}

		return $user;
	}

	/**
	 * @return array{0: string, 1: string}|null
	 */
	private static function credentials(): ?array {
		foreach (['Authorization', 'X-Api-Key'] as $header) {
			$value = self::header($header);
			if ($value === '') {
				continue;
			}
			$value = trim($value);

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
					$value = trim(substr($value, strlen($scheme)));
					break;
				}
			}
			if (str_contains($value, '/')) {
				[$user, $secret] = explode('/', $value, 2);
				return self::pair($user, $secret);
			}

			// A bare secret needs the account named separately, since FreshRSS users each have their own.
			$secret = $value;
			$user = is_string($_GET['user'] ?? null) ? $_GET['user'] : '';
			if ($user !== '' && $secret !== '') {
				return self::pair($user, $secret);
			}
		}

		return null;
	}

	/**
	 * @return array{0: string, 1: string}|null
	 */
	private static function pair(string $user, string $secret): ?array {
		$user = trim(rawurldecode($user));
		$secret = trim(rawurldecode($secret));
		return $user !== '' && $secret !== '' ? [$user, $secret] : null;
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
