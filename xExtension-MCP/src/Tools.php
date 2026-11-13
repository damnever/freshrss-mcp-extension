<?php
declare(strict_types=1);

/**
 * The read-only tools offered to MCP clients, built on FreshRSS' own models.
 *
 * Handlers return plain arrays which are serialised as JSON inside the tool result. Nothing
 * here writes: FreshRSS is only read through the same DAOs the web interface uses, so a
 * client sees exactly what that user would see when logged in.
 */
final class FreshMcp_Tools {
	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT = 100;
	private const DEFAULT_MAX_CHARS = 4000;
	private const MAX_MAX_CHARS = 20000;
	/** Entry orderings accepted by FreshRSS_EntryDAO::listWhere, narrowed to the useful two. */
	private const SORTS = ['date', 'id'];

	/** @var list<FreshRSS_Category>|null */
	private static ?array $categories = null;

	/** @return list<array<string,mixed>> */
	public static function definitions(): array {
		$entryProperties = [
			'limit' => ['type' => 'integer', 'description' => 'Maximum entries to return, 1-' . self::MAX_LIMIT . ' (default ' . self::DEFAULT_LIMIT . ').'],
			'since' => ['type' => 'integer', 'description' => 'Only entries published at or after this Unix timestamp.'],
			'until' => ['type' => 'integer', 'description' => 'Only entries published at or before this Unix timestamp.'],
			'include_content' => ['type' => 'boolean', 'description' => 'Include the body of each entry as plain text. Off by default to keep replies small.'],
			'max_chars' => ['type' => 'integer', 'description' => 'Truncate each body to this many characters, 1-' . self::MAX_MAX_CHARS .
		'(default ' . self::DEFAULT_MAX_CHARS . ').'],
			'sort' => ['type' => 'string', 'enum' => ['date', 'id'], 'description' => "'date' orders by publication (default), 'id' by arrival in FreshRSS."],
			'continuation' => ['type' => 'string', 'description' => 'The continuation token from a previous reply, to ask for the next page.'],
		];

		return [
			[
				'name' => 'list_feeds',
				'title' => 'List feeds',
				'description' => 'Every feed of the account, grouped by category, each with its unread count. ' .
					'Use the returned ids to narrow get_unread.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'category' => ['type' => 'string', 'description' => 'Keep only categories whose name contains this text (case-insensitive).'],
					'include_totals' => ['type' => 'boolean', 'description' => 'Also count the entries kept per feed, not only the unread ' .
					'ones. Costs an extra query per feed, so it is off by default.'],
					'include_hidden' => ['type' => 'boolean', 'description' => 'Also list feeds hidden from the reader.'],
				]],
			],
			[
				'name' => 'get_unread',
				'title' => 'Unread queue',
				'description' => 'Unread entries for triage, newest first: id, title, link, feed, publication time. ' .
					'Body text is left out unless include_content is set, since a queue is usually wide.',
				'inputSchema' => ['type' => 'object', 'properties' => $entryProperties + [
					'feed_id' => ['type' => 'integer', 'description' => 'Limit to one feed (see list_feeds).'],
					'category_id' => ['type' => 'integer', 'description' => 'Limit to one category (see list_feeds).'],
				]],
			],
			[
				'name' => 'search',
				'title' => 'Search entries',
				'description' => 'Search across all stored entries with FreshRSS search syntax, evaluated by the database: ' .
					'bare words match title and body, and search:foo, intitle:foo, inurl:foo, author:foo, ' .
					'feed_id:123, label:foo, tag:foo, date:2026-11-01, after:2026-11-01, before:2026-11-01, ' .
					'is:read, is:unread, is:favorite and Boolean groups are understood. Prefix a term with - to exclude it.',
				'inputSchema' => ['type' => 'object', 'properties' => $entryProperties + [
					'query' => ['type' => 'string', 'description' => 'The search, in FreshRSS syntax. Required.'],
					'unread_only' => ['type' => 'boolean', 'description' => 'Restrict the search to unread entries.'],
				]],
			],
			[
				'name' => 'get_article',
				'title' => 'Read one entry',
				'description' => 'One entry in full, as plain text, for summarising or quoting.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'id' => ['type' => 'string', 'description' => 'The entry id as returned by get_unread, search or get_article.'],
					'feed_id' => ['type' => 'integer', 'description' => 'With guid: the feed the entry belongs to.'],
					'guid' => ['type' => 'string', 'description' => 'With feed_id: the entry guid, when no numeric id is at hand.'],
				'max_chars' => ['type' => 'integer', 'description' => 'Truncate the body to this many characters, 1-' . self::MAX_MAX_CHARS .
					'(default ' . self::DEFAULT_MAX_CHARS . ').'],
					'fetch_full_content' => ['type' => 'boolean', 'description' => 'Ask FreshRSS to retrieve the article from its web page, ' .
					'for feeds that only publish a teaser. Slower, and it contacts the publisher.'],
				]],
			],
			[
				'name' => 'feed_stats',
				'title' => 'Account summary',
				'description' => 'How much there is to read: totals for feeds, categories, unread and starred entries, ' .
					'plus the ten noisiest feeds. Cheap way to start a triage conversation.',
				'inputSchema' => ['type' => 'object', 'properties' => []],
			],
		];
	}

	public static function exists(string $name): bool {
		return in_array($name, ['list_feeds', 'get_unread', 'search', 'get_article', 'feed_stats'], true);
	}

	/**
	 * Run a tool. Tool failures come back inside the result (isError), per the MCP spec, so
	 * that the model can read and react to them; only protocol mistakes throw.
	 *
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	public static function call(string $name, array $arguments): array {
		try {
			$data = match ($name) {
				'list_feeds' => self::listFeeds($arguments),
				'get_unread' => self::entries($arguments, true),
				'search' => self::search($arguments),
				'get_article' => self::article($arguments),
				'feed_stats' => self::stats(),
				default => throw new InvalidArgumentException("unknown tool '{$name}'"),
			};
		} catch (Minz_BadRequestException | InvalidArgumentException $e) {	// @phpstan-ignore catch.neverThrown
			// The message is the answer: what a model needs to fix its call. FreshRSS_BooleanSearch
			// throws Minz_BadRequestException, although its own signature does not say so.
			return self::failure($e->getMessage());
		} catch (Throwable $e) {	// @phpstan-ignore catch.neverThrown
			// The FreshRSS DAOs carry no @throws annotations, so keep a net and log what they do throw.
			Minz_Log::warning('MCP: tool ' . $name . ' failed: ' . $e::class . ': ' . $e->getMessage());
			return self::failure('FreshRSS could not carry out this request; see its logs.');
		}

		return self::success($data);
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function listFeeds(array $arguments): array {
		$needle = mb_strtolower(self::stringArg($arguments, 'category', ''));
		$includeTotals = self::boolArg($arguments, 'include_totals', false);
		$includeHidden = self::boolArg($arguments, 'include_hidden', false);

		$categories = [];
		$feedCount = 0;
		foreach (self::categories() as $category) {
			if ($needle !== '' && !str_contains(mb_strtolower($category->name()), $needle)) {
				continue;
			}
			$feeds = [];
			foreach ($category->feeds() as $feed) {
				if (!$includeHidden && $feed->priority() === FreshRSS_Feed::PRIORITY_HIDDEN) {
					continue;
				}
				$feedCount++;
				$row = [
					'id' => $feed->id(),
					'title' => $feed->name(),
					'unread' => $feed->nbNotRead(),
					'site' => $feed->website(true),
					'url' => $feed->url(false),
				];
				if ($includeTotals) {
					$row['total'] = $feed->nbEntries();
				}
				$feeds[] = $row;
			}
			if ($feeds !== []) {
				$categories[] = ['id' => $category->id(), 'name' => $category->name(), 'feeds' => $feeds];
			}
		}

		return ['categories' => $categories, 'feeds' => $feedCount, 'unread' => FreshRSS_Category::countUnread(self::categories())];
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function search(array $arguments): array {
		$query = self::stringArg($arguments, 'query', '');
		if ($query === '') {
			throw new InvalidArgumentException('search needs a query.');
		}
		// FreshRSS reads an unread constraint from the query itself, so that `is:read` keeps working.
		if (self::boolArg($arguments, 'unread_only', false) && !preg_match('/is\s*:\s*(un)?read/i', $query)) {
			$query = 'is:unread ' . $query;
		}

		return self::entries($arguments + ['search' => $query], false);
	}

	/**
	 * Shared by get_unread and search.
	 *
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function entries(array $arguments, bool $unreadOnly): array {
		$limit = self::intArg($arguments, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
		$maxChars = self::intArg($arguments, 'max_chars', self::DEFAULT_MAX_CHARS, 1, self::MAX_MAX_CHARS);
		$withContent = self::boolArg($arguments, 'include_content', false);
		$sort = self::sort($arguments);

		$search = self::stringArg($arguments, 'search', '');
		$feedId = self::intOpt($arguments, 'feed_id');
		$category_id = self::intOpt($arguments, 'category_id');

		$booleanSearch = new FreshRSS_BooleanSearch($search);
		$extra = new FreshRSS_Search('');
		$since = self::intOpt($arguments, 'since');
		$until = self::intOpt($arguments, 'until');
		if ($since !== null) {
			$extra->setMinDate($since);
		}
		if ($until !== null) {
			$extra->setMaxDate($until);
		}
		if ($since !== null || $until !== null) {
			$booleanSearch->add($extra);
		}

		// A feed narrows the query, a category narrows the SQL join; both keep any search terms.
		$type = 'A';
		$id = 0;
		if ($feedId !== null) {
			$type = 'f';
			$id = $feedId;
		} elseif ($category_id !== null) {
			$type = 'c';
			$id = $category_id;
		}

		$state = $unreadOnly ? FreshRSS_Entry::STATE_NOT_READ : FreshRSS_Entry::STATE_ALL;
		$token = self::stringArg($arguments, 'continuation', '');
		$continuationId = '0';
		$continuationValues = [];
		if ($token !== '') {
			[$continuationId, $continuationValues] = self::parseContinuation($token, $sort);
		}

		// Ask for more rows than we return: the extra one says whether there is a next page.
		// With a continuation, FreshRSS starts at the entry the token names, which the client
		// has already seen, so one more row is needed on top of that.
		$entryDAO = FreshRSS_Factory::createEntryDao();
		$rows = iterator_to_array($entryDAO->listWhere($type, $id, $state, $booleanSearch,
			sort: $sort, order: 'DESC', continuation_id: $continuationId, continuation_values: $continuationValues,
			limit: $limit + ($continuationId === '0' ? 1 : 2)), false);

		if ($continuationId !== '0') {
			array_shift($rows);
		}
		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			$rows = array_slice($rows, 0, $limit);
		}

		$titles = self::feedTitles();
		$entries = [];
		foreach ($rows as $entry) {
			$item = self::entrySummary($entry, $titles);
			if ($withContent) {
				$item['content'] = self::toText($entry->content(false), $maxChars);
			}
			$entries[] = $item;
		}

		$result = ['count' => count($entries), 'entries' => $entries];
		if ($hasMore && $rows !== []) {
			$result['continuation'] = self::token(end($rows), $sort);
		}
		if ($search !== '') {
			$result['query'] = $search;
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function article(array $arguments): array {
		$maxChars = self::intArg($arguments, 'max_chars', self::DEFAULT_MAX_CHARS, 1, self::MAX_MAX_CHARS);
		$id = self::stringArg($arguments, 'id', '');
		$feedId = self::intOpt($arguments, 'feed_id');
		$guid = self::stringArg($arguments, 'guid', '');

		$entryDAO = FreshRSS_Factory::createEntryDao();
		if ($id !== '') {
			$entry = ctype_digit($id) ? $entryDAO->searchById($id) : null;
		} elseif ($feedId !== null && $guid !== '') {
			$entry = $entryDAO->searchByGuid($feedId, $guid);
		} else {
			throw new InvalidArgumentException('get_article needs an id, or both feed_id and guid.');
		}
		if ($entry === null) {
			throw new InvalidArgumentException('No such entry: it may have been purged, or it belongs to another user.');
		}

		if (self::boolArg($arguments, 'fetch_full_content', false)) {
			// Asks the publisher for the page, for feeds that only deliver a teaser.
			$entry->loadCompleteContent();
		}

		$result = self::entrySummary($entry, self::feedTitles());
		$authors = $entry->authors();
		if ($authors !== []) {
			$result['author'] = implode('; ', $authors);
		}
		$tags = $entry->tags();
		if ($tags !== []) {
			$result['tags'] = $tags;
		}
		$result['published'] = $entry->machineReadableDate();
		$result['added'] = date(DATE_ATOM, (int) $entry->dateAdded(true));
		$result['content'] = self::toText($entry->content(false), $maxChars);

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function stats(): array {
		$categories = self::categories();
		$feeds = [];
		foreach ($categories as $category) {
			foreach ($category->feeds() as $feed) {
				$feeds[] = $feed;
			}
		}

		$noisiest = [];
		foreach ($feeds as $feed) {
			if ($feed->priority() !== FreshRSS_Feed::PRIORITY_HIDDEN && $feed->nbNotRead() > 0) {
				$noisiest[$feed->id()] = ['feed_id' => $feed->id(), 'title' => $feed->name(), 'unread' => $feed->nbNotRead()];
			}
		}
		usort($noisiest, static fn(array $a, array $b): int => $b['unread'] <=> $a['unread']);

		$entryDAO = FreshRSS_Factory::createEntryDao();
		$starred = $entryDAO->countUnreadReadFavorites();

		return [
			'user' => Minz_User::name(),
			'categories' => count($categories),
			'feeds' => count($feeds),
			'unread' => FreshRSS_Category::countUnread($categories),
			'entries_stored' => $entryDAO->count(),
			'starred' => max(0, (int) ($starred['all'] ?? 0)),
			'starred_unread' => max(0, (int) ($starred['unread'] ?? 0)),
			'most_unread' => array_slice($noisiest, 0, 10),
		];
	}

	/**
	 * @param array<int,string> $titles feed id => title
	 * @return array<string,mixed>
	 */
	private static function entrySummary(FreshRSS_Entry $entry, array $titles): array {
		return [
			'id' => $entry->id(),
			'title' => $entry->title(),
			'link' => $entry->link(),
			'feed_id' => $entry->feedId(),
			'feed' => $titles[$entry->feedId()] ?? null,
			'published' => $entry->machineReadableDate(),
			'unread' => $entry->isRead() === false,
			'starred' => $entry->isFavorite() === true,
		];
	}

	/**
	 * Turn stored HTML into readable text without losing the links.
	 *
	 * @param string $html a URL to the article, and a placeholder so that only the first
	 *                     occurrence is turned into a link.
	 */
	private static function toText(string $html, int $maxChars): string {
		$html = trim($html);
		if ($html === '') {
			return '';
		}

		$html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
		$html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? $html;
		// A bare URL is dropped by strip_tags below, so name it first.
		$html = preg_replace('#<a\b[^>]*?href=["\']([^"\']+)["\'][^>]*>\s*</a>#i', '$1', $html) ?? $html;
		$html = preg_replace('#<a\b[^>]*?href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '$2 <$1>', $html) ?? $html;
		$html = preg_replace('#</?(p|div|br|li|tr|h[1-6]|blockquote|pre|ul|ol|figure|figcaption)\b[^>]*>#i', "\n", $html) ?? $html;

		$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/[ \t\x{00A0}\x{00AD}]+/u', ' ', $text) ?? $text;
		$text = preg_replace('/\n(?: *\n){2,}/', "\n\n", $text) ?? $text;
		$text = trim($text);

		if (mb_strlen($text) <= $maxChars) {
			return $text;
		}

		return rtrim(mb_substr($text, 0, $maxChars)) . ' […truncated; raise max_chars or use get_article]';
	}

	/** @return array{0: string, 1: list<string|int>} */
	private static function parseContinuation(string $token, string $sort): array {
		$parts = explode(':', $token);
		$id = $parts[0] ?? '';
		if (!ctype_digit($id)) {
			throw new InvalidArgumentException('That continuation token is not valid for this server.');
		}
		// FreshRSS needs the sort key of the last entry sent, which for date ordering is its date.
		$values = isset($parts[1]) && ctype_digit($parts[1]) && $sort === 'date' ? [(int) $parts[1]] : [];

		return [$id, $values];
	}

	private static function token(FreshRSS_Entry $entry, string $sort): string {
		return $sort === 'date' ? $entry->id() . ':' . $entry->date(true) : $entry->id();
	}

	private static function sort(array $arguments): string {
		$sort = self::stringArg($arguments, 'sort', 'date');
		if (!in_array($sort, self::SORTS, true)) {
			throw new InvalidArgumentException("sort must be one of: " . implode(', ', self::SORTS) . '.');
		}
		return $sort;
	}

	/** @return array<int, string> feed id => title, in one query */
	private static function feedTitles(): array {
		$titles = [];
		foreach (FreshRSS_Factory::createFeedDao()->listFeeds() as $feed) {
			$titles[$feed->id()] = $feed->name();
		}
		return $titles;
	}

	/** @return list<FreshRSS_Category> */
	private static function categories(): array {
		if (self::$categories === null) {
			self::$categories = array_values(FreshRSS_Factory::createCategoryDao()->listCategories(true, false));
		}
		return self::$categories;
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	private static function success(array $data): array {
		return [
			'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
			'isError' => false,
		];
	}

	/** @return array<string,mixed> */
	private static function failure(string $message): array {
		return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
	}

	/** @param array<string,mixed> $arguments */
	private static function stringArg(array $arguments, string $name, string $default): string {
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
	private static function intOpt(array $arguments, string $name): ?int {
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
	private static function intArg(array $arguments, string $name, int $default, int $min, int $max): int {
		$value = self::intOpt($arguments, $name) ?? $default;
		if ($value < $min || $value > $max) {
			throw new InvalidArgumentException("{$name} must be between {$min} and {$max}.");
		}
		return $value;
	}

	/** @param array<string,mixed> $arguments */
	private static function boolArg(array $arguments, string $name, bool $default): bool {
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
}
