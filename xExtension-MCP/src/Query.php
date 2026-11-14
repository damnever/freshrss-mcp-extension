<?php
declare(strict_types=1);

/**
 * Checks a search string before FreshRSS parses it.
 *
 * FreshRSS reads the operators it knows and quietly ignores the rest, which is the wrong
 * behaviour for a tool a model drives: `date:` with a value it cannot parse drops the date
 * constraint altogether and hands back everything, while an operator that does not exist turns
 * into free text matching nothing. Either way the answer looks like a search that worked. This
 * is not a second search engine and adds nothing to what can be expressed: it only refuses the
 * words FreshRSS would have dropped, so that a filter is never silently wider than what was asked.
 *
 * @see https://freshrss.github.io/FreshRSS/en/users/10_filter.html
 */
final class FreshMcp_Query {

	/** Operators whose value is one or more numeric ids. */
	private const ID_KEYS = ['f', 'c', 'e'];

	/**
	 * Operators whose value is a list of numeric ids, or `*` for any: `L:` for a label, `S:` for a
	 * saved search. Lower case, like every key here, after FreshMcp_Query::wordRefusal folds it.
	 */
	private const ANY_KEYS = ['l', 's'];

	/** Operators whose value FreshRSS reads as an ISO 8601 time interval. */
	private const DATE_KEYS = ['date', 'pubdate', 'mdate', 'userdate'];

	/** Operators whose value is text, a quoted phrase or a /regex/. */
	private const TEXT_KEYS = ['author', 'intitle', 'intext', 'inurl', 'label', 'labels', 'search'];

	/** Operators people reach for that FreshRSS does not have, each with what to write instead. */
	private const REPLACEMENTS = [
		'is' => 'there is no state operator: pass unread_only true to search, or use get_unread, which is unread-only.',
		'state' => 'there is no state operator: pass unread_only true to search, or use get_unread.',
		'read' => 'there is no state operator: pass unread_only true to search, or use get_unread.',
		'unread' => 'there is no state operator: pass unread_only true to search, or use get_unread.',
		'favorite' => 'there is no operator for favourites here: feed_stats counts them, and every entry returned says whether it is starred.',
		'favourite' => 'there is no operator for favourites here: feed_stats counts them, and every entry returned says whether it is starred.',
		'starred' => 'there is no operator for favourites here: feed_stats counts them, and every entry returned says whether it is starred.',
		'tag' => 'a tag is written #name, not tag:name.',
		'tags' => 'a tag is written #name, not tags:name.',
		'feed' => 'a feed is written f:123.',
		'feed_id' => 'a feed is written f:123.',
		'feedid' => 'a feed is written f:123.',
		'category' => 'a category is written c:12.',
		'category_id' => 'a category is written c:12.',
		'id' => 'an entry is written e:1639310674957894.',
		'entry_id' => 'an entry is written e:1639310674957894.',
		'after' => 'use the since argument, or date:2026-11-01/ for from-a-date-onwards.',
		'since' => 'use the since argument, or date:2026-11-01/.',
		'before' => 'use the until argument, or date:/2026-11-01 for up-to-a-date.',
		'until' => 'use the until argument, or date:/2026-11-01.',
		'title' => 'a word in the title is written intitle:keyword.',
		'body' => 'a word in the body is written intext:keyword.',
		'url' => 'a word in the link is written inurl:keyword.',
	];

	/**
	 * Why FreshRSS would not have filtered on part of this search, or null when it would have
	 * filtered on all of it.
	 */
	public static function refusal(string $query): ?string {
		[$words, $why] = self::words($query);
		if ($why !== null) {
			return $why;
		}

		foreach ($words as $word) {
			$refusal = self::wordRefusal($word);
			if ($refusal !== null) {
				return $refusal;
			}
		}

		return null;
	}

	/**
	 * What the syntax actually is, for the message that says it is not.
	 */
	public static function supported(): string {
		return 'f:123, c:12, e:1639310674957894, L:3, S:1, label:name, search:name, author:name, '
			. 'intitle:word, intext:word, inurl:word, #tag, date:2026-11-01, pubdate:PT12H, mdate:, userdate:, '
			. 'groups in ( ) joined with OR, and ! or - in front of any of them to exclude it';
	}

	/**
	 * The words of a query, split on whitespace and parentheses, with quoted phrases and escapes
	 * kept whole.
	 *
	 * @return array{0: list<string>, 1: ?string} the words, or why there are none usable
	 */
	private static function words(string $query): array {
		$words = [];
		$word = '';
		$depth = 0;
		$quote = '';
		$length = strlen($query);

		for ($i = 0; $i < $length; $i++) {
			$char = $query[$i];

			if ($quote !== '') {
				$word .= $char;
				if ($char === '\\' && $i + 1 < $length) {
					$word .= $query[++$i];
				} elseif ($char === $quote) {
					$quote = '';
				}
				continue;
			}

			if ($char === '\\' && $i + 1 < $length) {
				// An escaped parenthesis is text, not grouping.
				$word .= $char . $query[++$i];
				continue;
			}

			if ($char === "'" || $char === '"') {
				$quote = $char;
				$word .= $char;
				continue;
			}

			if ($char === '(' || $char === ')') {
				if ($word !== '') {
					$words[] = $word;
					$word = '';
				}
				$depth += $char === '(' ? 1 : -1;
				if ($depth < 0) {
					return [[], 'the search has a closing parenthesis with no opening one, which FreshRSS '
						. 'cannot parse; it would be treated as text.'];
				}
				continue;
			}

			if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" || $char === "\v" || $char === "\f") {
				if ($word !== '') {
					$words[] = $word;
					$word = '';
				}
				continue;
			}

			$word .= $char;
		}

		if ($quote !== '') {
			return [[], 'the search has a ' . ($quote === "'" ? 'single' : 'double') .
				' quote that is never closed, which FreshRSS cannot parse.'];
		}
		if ($depth !== 0) {
			return [[], 'the search has ' . $depth . ' unclosed parenthesis, which FreshRSS would not have '
				. 'applied as a group.'];
		}
		if ($word !== '') {
			$words[] = $word;
		}

		return [$words, null];
	}

	/**
	 * Whether FreshRSS has an operator for one word of the query.
	 */
	private static function wordRefusal(string $word): ?string {
		if (strcasecmp($word, 'OR') === 0) {
			return null;
		}

		// A leading ! or - only says “not”, and a lone one negates the group that follows it.
		$subject = $word[0] === '!' || $word[0] === '-' ? substr($word, 1) : $word;
		if ($subject === '') {
			return null;
		}
		if ($subject[0] === '#') {
			return self::textRefusal('#', substr($subject, 1), $word, 'a tag name');
		}

		$colon = strpos($subject, ':');
		if ($colon === false) {
			return null;
		}

		$key = substr($subject, 0, $colon);
		$value = substr($subject, $colon + 1);
		if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $key) !== 1 || str_starts_with($value, '//')) {
			// Not an operator shape, so it is text: a URL in a title, for instance.
			return null;
		}

		$lower = strtolower($key);
		if (in_array($lower, self::ID_KEYS, true)) {
			return self::idRefusal($key, $value, $word, false);
		}
		if (in_array($lower, self::ANY_KEYS, true)) {
			if ($lower === 's' && $key !== 'S') {
				// FreshRSS reads only an upper-case S: as a saved-search id, and takes a lower-case one
				// as text to search for.
				return '“' . $word . '” is not an operator: a saved search id is written S:1, with a capital S, '
					. 'and a label id L:3.';
			}
			return self::idRefusal(strtoupper($lower), $value, $word, true);
		}
		if (in_array($lower, self::DATE_KEYS, true)) {
			return self::dateRefusal($value, $word);
		}
		if (in_array($lower, self::TEXT_KEYS, true)) {
			return self::textRefusal($key, $value, $word, 'a value');
		}

		return '“' . $word . '” is not something FreshRSS searches for: ' .
			(self::REPLACEMENTS[$lower] ?? 'it would be searched for as plain text. Supported operators are '
				. self::supported() . '.');
	}

	/**
	 * An id list: 123, or 123,234, or for a label or saved search `*` for any.
	 */
	private static function idRefusal(string $key, string $value, string $word, bool $any): ?string {
		if ($any && $value === '*') {
			return null;
		}
		if ($value !== '' && preg_match('/^[0-9]+(,[0-9]+)*$/', $value) === 1) {
			return null;
		}

		return '“' . $word . '” is not a value FreshRSS can use: ' . $key . ': takes ids like '
			. ($any ? '3 or 3,4 or *.' : '123 or 123,234.');
	}

	/**
	 * A time interval. Not re-parsed here, only rejected when it cannot be one at all, since a
	 * value FreshRSS cannot read is a filter it silently drops.
	 */
	private static function dateRefusal(string $value, string $word): ?string {
		if ($value !== '' && preg_match('#^[-0-9Zz.,:+/PTYSMDWHysmdwh]+$#', $value) === 1 &&
			preg_match('/[0-9]/', $value) === 1) {
			return null;
		}

		return '“' . $word . '” is not a date FreshRSS can read, so the date would not have been filtered '
			. 'on at all. Give it a year, a month or a moment like 2026-11-01T13:30, a range like '
			. '2026-11-01/2026-11-08, or a past duration like PT12H.';
	}

	/**
	 * Text, a quoted phrase or a /regex/, none of them empty.
	 */
	private static function textRefusal(string $key, string $value, string $word, string $what): ?string {
		if ($value === '') {
			return '“' . $word . '” names ' . $what . ' but does not say which one, so it filters nothing.';
		}
		if ($value[0] === '/' && (strlen($value) < 2 || strpos($value, '/', 1) === false)) {
			return '“' . $word . '” starts a regular expression and never closes it; a regex is written /like/this/i.';
		}

		return null;
	}
}
