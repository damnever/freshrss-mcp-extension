<?php
declare(strict_types=1);

/**
 * Typed reads of tool arguments, shared by every tool.
 *
 * Each reader either returns a value of the type it names or throws InvalidArgumentException with a
 * sentence a model can act on; FreshMcp_Tools::call() turns that into an isError result. Nothing is
 * coerced silently: a limit of "lots" is a refusal, not the default.
 */
final class FreshMcp_Arguments {

	/** @param array<string,mixed> $arguments */
	public static function string(array $arguments, string $name, string $default): string {
		$value = $arguments[$name] ?? null;
		if ($value === null) {
			return $default;
		}
		if (!is_string($value)) {
			throw new InvalidArgumentException("{$name} must be a string.");
		}

		return trim($value);
	}

	/** @param array<string,mixed> $arguments */
	public static function intOpt(array $arguments, string $name): ?int {
		$value = $arguments[$name] ?? null;
		if ($value === null) {
			return null;
		}
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && ctype_digit($value)) {
			return (int) $value;
		}

		throw new InvalidArgumentException("{$name} must be an integer.");
	}

	/** @param array<string,mixed> $arguments */
	public static function int(array $arguments, string $name, int $default, int $min, int $max): int {
		$value = self::intOpt($arguments, $name) ?? $default;
		if ($value < $min || $value > $max) {
			throw new InvalidArgumentException("{$name} must be between {$min} and {$max}.");
		}

		return $value;
	}

	/** @param array<string,mixed> $arguments */
	public static function bool(array $arguments, string $name, bool $default): bool {
		$value = $arguments[$name] ?? null;
		if ($value === null) {
			return $default;
		}
		if (is_bool($value)) {
			return $value;
		}
		if ($value === 'true' || $value === 'false') {
			return $value === 'true';
		}

		throw new InvalidArgumentException("{$name} must be a boolean.");
	}

	/**
	 * A positive id of a feed, category or label.
	 *
	 * @param array<string,mixed> $arguments
	 */
	public static function idOpt(array $arguments, string $name): ?int {
		$value = self::intOpt($arguments, $name);
		if ($value !== null && $value <= 0) {
			throw new InvalidArgumentException("{$name} must be a positive integer.");
		}

		return $value;
	}

	/** @param array<string,mixed> $arguments */
	public static function id(array $arguments, string $name): int {
		return self::idOpt($arguments, $name) ?? throw new InvalidArgumentException("{$name} is required.");
	}

	/**
	 * A list of entry ids, as FreshRSS writes them: decimal strings of up to 19 digits.
	 *
	 * Integers are taken too, since a client may well send the id it was given as a number, but only
	 * whole, non-negative ones. Duplicates are dropped, so a count in the answer counts entries.
	 *
	 * @param array<string,mixed> $arguments
	 * @return non-empty-list<numeric-string>
	 */
	public static function entryIds(array $arguments, string $name, int $max): array {
		$value = $arguments[$name] ?? null;
		if (!is_array($value) || !array_is_list($value) || $value === []) {
			throw new InvalidArgumentException("{$name} must be a non-empty list of entry ids, as returned by get_unread "
				. 'or search.');
		}
		if (count($value) > $max) {
			throw new InvalidArgumentException("{$name} takes at most {$max} ids per call; split the list.");
		}
		$ids = [];
		foreach ($value as $id) {
			if (is_int($id) && $id > 0) {
				$id = (string) $id;
			}
			if (!is_string($id) || !ctype_digit($id) || strlen($id) > 19 || $id[0] === '0') {
				throw new InvalidArgumentException("{$name} holds " . var_export($id, true) . ', which is not an entry '
					. 'id. Entry ids are the decimal strings get_unread and search return.');
			}
			$ids[$id] = true;
		}

		/** @var non-empty-list<numeric-string> */
		return array_map('strval', array_keys($ids));
	}

	/**
	 * A display name for a category, label or feed, in the HTML-escaped form FreshRSS stores names in.
	 *
	 * The web interface escapes every name it is given, so storing raw text here would make "A & B"
	 * created over MCP a different name from "A & B" created in the browser. Decoding first means a
	 * name copied back from a list_* answer, already escaped, is not escaped twice.
	 *
	 * @param array<string,mixed> $arguments
	 */
	public static function nameOpt(array $arguments, string $name): ?string {
		$value = self::string($arguments, $name, '');
		if ($value === '') {
			return null;
		}
		if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
			throw new InvalidArgumentException("{$name} may not contain control characters or line breaks.");
		}
		$stored = htmlspecialchars(htmlspecialchars_decode($value, ENT_QUOTES), ENT_COMPAT, 'UTF-8');
		// FreshRSS cuts category and label names to this many bytes without saying so; refuse instead,
		// so that the name the caller chose is the name it gets.
		if (strlen($stored) > FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE) {
			throw new InvalidArgumentException("{$name} is too long; keep it under about 150 characters.");
		}

		return $stored;
	}

	/** @param array<string,mixed> $arguments */
	public static function name(array $arguments, string $name): string {
		return self::nameOpt($arguments, $name) ?? throw new InvalidArgumentException("{$name} is required and may not "
			. 'be empty.');
	}
}
