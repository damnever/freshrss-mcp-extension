<?php
declare(strict_types=1);

/**
 * The read-only tools offered to MCP clients, built on FreshRSS' own models.
 *
 * Handlers return plain arrays which are serialised as JSON inside the tool result. Nothing here
 * writes: FreshRSS is only read through the same DAOs the web interface uses, so a client sees
 * exactly what that user would see when logged in.
 *
 * Every argument is checked against the schema advertised by `tools/list`, and anything the tool
 * cannot act on is refused with a message rather than quietly ignored. Both choices have the same
 * reason: a model cannot tell a filtered answer from one where the filter was dropped, so a
 * request that would return more than was asked for has to come back as an error instead.
 */
final class FreshMcp_Tools {
	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT = 100;
	private const DEFAULT_MAX_CHARS = 4000;
	private const MAX_MAX_CHARS = 20000;
	/** The latest date the since and until arguments take: 2100-01-01, in Unix seconds. */
	private const MAX_TIMESTAMP = 4102444800;
	/** Entry orderings accepted by FreshRSS_EntryDAO::listWhere, narrowed to the useful two.
	 * @var list<'date'|'id'>
	 */
	private const SORTS = ['date', 'id'];

	/**
	 * Categories of the accounts this request looked at, so that a tool reading them twice does not
	 * query twice. Reset at the start of every request: a worker process lives on to serve the next
	 * one, and a feed added in the meantime has to show up there.
	 *
	 * @var array<string, list<FreshRSS_Category>>
	 */
	private static array $categories = [];

	/** @return list<array<string,mixed>> */
	public static function definitions(): array {
		$definitions = [];
		$outputs = self::outputs();
		foreach (self::specs() as $name => $spec) {
			$definitions[] = ['name' => $name] + $spec + ['outputSchema' => $outputs[$name]];
		}

		return $definitions;
	}

	public static function exists(string $name): bool {
		return array_key_exists($name, self::specs());
	}

	/**
	 * Drop what was memoised while answering. Called once per request.
	 */
	public static function reset(): void {
		self::$categories = [];
	}

	/**
	 * Run a tool. Tool failures come back inside the result (isError), per the MCP spec, so that the
	 * model can read and react to them; only protocol mistakes throw.
	 *
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	public static function call(string $name, array $arguments): array {
		try {
			self::checkArguments($name, $arguments);
			$data = self::run($name, $arguments);
		} catch (Minz_BadRequestException | InvalidArgumentException $e) {
			// The message is the answer: what a model needs to fix its call. FreshRSS_BooleanSearch
			// throws Minz_BadRequestException, although its own signature does not say so.
			return self::failure($e->getMessage());
		} catch (Throwable $e) {
			// Anything else is not a complaint about the call, so it is logged and answered with a
			// sentence rather than with a message that belongs to FreshRSS internals.
			Minz_Log::warning('MCP: tool ' . $name . ' failed: ' . $e::class . ': ' . $e->getMessage());
			return self::failure('FreshRSS could not carry out this request; see its logs.');
		}

		return self::success($name, $data);
	}

	/**
	 * One handler, chosen by name.
	 *
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 * @throws Throwable a complaint of our own as InvalidArgumentException, or anything the FreshRSS
	 *         models raise: they document nothing, and what comes out of them is sorted by the caller
	 *         rather than guessed at here.
	 */
	private static function run(string $name, array $arguments): array {
		return match ($name) {
			'list_feeds' => self::listFeeds($arguments),
			'get_unread' => self::entries($arguments, true),
			'search' => self::search($arguments),
			'get_article' => self::article($arguments),
			'feed_stats' => self::stats(),
			default => throw new InvalidArgumentException("unknown tool '{$name}'"),
		};
	}

	/**
	 * The tools, and what each one takes.
	 *
	 * One description serves both what is advertised and what is accepted, so that the schema a
	 * client reads cannot drift away from the handler behind it.
	 *
	 * @return array<string, array<string,mixed>>
	 */
	private static function specs(): array {
		return [
			'list_feeds' => [
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
			'get_unread' => [
				'title' => 'Unread queue',
				'description' => 'Unread entries for triage, newest first: id, title, link, feed, publication time. ' .
					'Body text is left out unless include_content is set, since a queue is usually wide.',
				'inputSchema' => ['type' => 'object', 'properties' => self::entryProperties() + [
					'feed_id' => ['type' => 'integer', 'description' => 'Limit to one feed (see list_feeds).'],
					'category_id' => ['type' => 'integer', 'description' => 'Limit to one category (see list_feeds).'],
				]],
			],
			'search' => [
				'title' => 'Search entries',
				'description' => 'Search the stored entries with FreshRSS search syntax, evaluated by the database. '
					. 'Bare words match title and body. f:123 is a feed, c:12 a category, e:1639310674957894 one entry, '
					. 'L:3 a label, S:1 a saved search, label:name and search:name by name, author:name, intitle:word, '
					. 'intext:word, inurl:word, #tag, and date:, pubdate:, mdate: and userdate: take an ISO 8601 '
					. 'interval such as 2026-11-01, 2026-11-01/2026-11-08 or PT12H. Groups in ( ) joined by OR, and ! or '
					. '- in front of a term excludes it. There is no operator for read or unread state: use unread_only. '
					. 'Anything FreshRSS could not have filtered on is refused rather than ignored, so an answer is never '
					. 'wider than what was asked for.',
				'inputSchema' => ['type' => 'object', 'properties' => self::entryProperties() + [
					'query' => ['type' => 'string', 'description' => 'The search, in FreshRSS syntax. Required.'],
					'unread_only' => ['type' => 'boolean', 'description' => 'Restrict the search to unread entries. This is ' .
						'an argument and not a search operator, because FreshRSS keeps read and unread as a state of '
						. 'the entry rather than as part of a query.'],
				], 'required' => ['query']],
			],
			'get_article' => [
				'title' => 'Read one entry',
				'description' => 'One entry in full, as plain text, for summarising or quoting.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'id' => ['type' => 'string', 'description' => 'The entry id as returned by get_unread, search or get_article.'],
					'feed_id' => ['type' => 'integer', 'description' => 'With guid: the feed the entry belongs to.'],
					'guid' => ['type' => 'string', 'description' => 'With feed_id: the entry guid, when no numeric id is at hand.'],
					'max_chars' => ['type' => 'integer', 'description' => 'Truncate the body to this many characters, 1-' .
						self::MAX_MAX_CHARS . ' (default ' . self::DEFAULT_MAX_CHARS . ').'],
					'fetch_full_content' => ['type' => 'boolean', 'description' => 'Ask FreshRSS to retrieve the article from ' .
						'its web page, for feeds that only publish a teaser. Works only for feeds that have an article ' .
						'CSS selector set in FreshRSS. Slower, and it makes the server contact the publisher: only http ' .
						'and https links that resolve to public addresses are fetched, and anything on a private, ' .
						'loopback or link-local address is refused.'],
				]],
			],
			'feed_stats' => [
				'title' => 'Account summary',
				'description' => 'How much there is to read: totals for feeds, categories, unread and starred entries, ' .
					'plus the ten noisiest feeds. Cheap way to start a triage conversation.',
				// An empty object and not an empty list: a JSON Schema is an object even with no properties.
				'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
			],
		];
	}

	/**
	 * The arguments shared by the two tools that return a list of entries.
	 *
	 * @return array<string, array<string,mixed>>
	 */
	private static function entryProperties(): array {
		return [
			'limit' => ['type' => 'integer', 'description' => 'Maximum entries to return, 1-' . self::MAX_LIMIT .
				' (default ' . self::DEFAULT_LIMIT . ').'],
			'since' => ['type' => 'integer', 'description' => 'Only entries published at or after this Unix timestamp, '
				. 'up to ' . gmdate('Y-m-d', self::MAX_TIMESTAMP) . '.'],
			'until' => ['type' => 'integer', 'description' => 'Only entries published at or before this Unix timestamp, '
				. 'up to ' . gmdate('Y-m-d', self::MAX_TIMESTAMP) . '.'],
			'include_content' => ['type' => 'boolean', 'description' => 'Include the body of each entry as plain text. '
				. 'Off by default to keep replies small.'],
			'max_chars' => ['type' => 'integer', 'description' => 'Truncate each body to this many characters, 1-' .
				self::MAX_MAX_CHARS . ' (default ' . self::DEFAULT_MAX_CHARS . ').'],
			'sort' => ['type' => 'string', 'enum' => self::SORTS, 'description' => "'date' orders by publication "
				. '(default), \'id\' by arrival in FreshRSS.'],
			'continuation' => ['type' => 'string', 'description' => 'The continuation token from a previous reply, to ask '
				. 'for the next page. It belongs to this account and to the sort order it was issued for.'],
		];
	}

	/**
	 * What each tool answers, as JSON Schema.
	 *
	 * The shapes below are what the handlers build, field for field. They do not require the fields
	 * that come and go: a body appears only when include_content was asked for, a continuation only
	 * when there is a next page. Requiring what is always there and describing what may be missing is
	 * the part that has to be right.
	 *
	 * @return array<string, array<string,mixed>>
	 */
	private static function outputs(): array {
		// The shapes below are what the handlers build, field for field. What comes and goes is not
		// required: a body appears only when include_content was asked for, a continuation only when
		// there is a page behind this one.
		return [
			'list_feeds' => self::listFeedsSchema(),
			'get_unread' => self::entriesSchema(),
			'search' => self::entriesSchema(true),
			'get_article' => self::articleSchema(),
			'feed_stats' => self::statsSchema(),
		];
	}

	/**
	* @return array<string,mixed>
	*/
	private static function listFeedsSchema(): array {
		return self::shape([
			'categories' => ['type' => 'array', 'items' => self::categorySchema()],
			'feeds' => ['type' => 'integer', 'description' => 'How many feeds are listed above.'],
			'unread' => ['type' => 'integer', 'description' => 'Unread entries across the whole account.'],
		], ['categories', 'feeds', 'unread']);
	}

	/**
	* @return array<string,mixed>
	*/
	private static function categorySchema(): array {
		return self::shape([
			'id' => ['type' => 'integer'],
			'name' => ['type' => 'string'],
			'feeds' => ['type' => 'array', 'items' => self::feedRowSchema()],
		], ['id', 'name', 'feeds']);
	}

	/**
	* @return array<string,mixed>
	*/
	private static function feedRowSchema(): array {
		return self::shape([
			'id' => ['type' => 'integer'],
			'title' => ['type' => 'string'],
			'unread' => ['type' => 'integer', 'description' => 'Entries of this feed not yet read.'],
			'site' => ['type' => 'string', 'description' => 'The publisher website, empty when the feed names none.'],
			'url' => ['type' => 'string', 'description' => 'The feed address FreshRSS pulls from.'],
			'total' => ['type' => 'integer', 'description' => 'Entries kept, only with include_totals.'],
		], ['id', 'title', 'unread', 'site', 'url']);
	}

	/**
	* @return array<string,mixed>
	*/
	private static function entriesSchema(bool $withQuery = false): array {
		$properties = [
			'count' => ['type' => 'integer', 'description' => 'Entries in this page, at most limit.'],
			'entries' => ['type' => 'array', 'items' => self::shape(self::entrySchema(), [
				'id', 'title', 'link', 'feed_id', 'published', 'unread', 'starred',
			])],
			'continuation' => ['type' => 'string', 'description' => 'Ask for the page after this one. Issued for '
				. 'this account and this sort order only.'],
		];
		if ($withQuery) {
			$properties['query'] = ['type' => 'string', 'description' => 'The search as it was carried out.'];
		}

		return self::shape($properties, ['count', 'entries']);
	}

	/**
	* @return array<string,mixed>
	*/
	private static function articleSchema(): array {
		return self::shape(self::entrySchema() + [
			'author' => ['type' => 'string', 'description' => 'Byline, when the entry carries one.'],
			'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
			'added' => ['type' => 'string', 'description' => 'When FreshRSS stored it, RFC 3339.'],
			'content' => ['type' => 'string', 'description' => 'The body as plain text, truncated to max_chars.'],
		], ['id', 'title', 'link', 'feed_id', 'published', 'unread', 'starred', 'added', 'content']);
	}

	/**
	* @return array<string,mixed>
	*/
	private static function statsSchema(): array {
		return self::shape([
			'user' => ['type' => 'string', 'description' => 'The account this answer describes.'],
			'categories' => ['type' => 'integer'],
			'feeds' => ['type' => 'integer'],
			'unread' => ['type' => 'integer'],
			'entries_stored' => ['type' => 'integer', 'description' => 'Entries in the database, read or not.'],
			'starred' => ['type' => ['integer', 'null'],
				'description' => 'Starred entries, or null when the count could not be read: null is not zero.'],
			'starred_unread' => ['type' => ['integer', 'null'], 'description' => 'Starred and unread, or null on a failed count.'],
			'most_unread' => ['type' => 'array', 'description' => 'Up to ten feeds, noisiest first.',
				'items' => self::shape([
					'feed_id' => ['type' => 'integer'],
					'title' => ['type' => 'string'],
					'unread' => ['type' => 'integer'],
				], ['feed_id', 'title', 'unread'])],
			'warnings' => ['type' => 'array', 'items' => ['type' => 'string'],
				'description' => 'What could not be counted, and so is null.'],
		], ['user', 'categories', 'feeds', 'unread', 'entries_stored', 'starred', 'starred_unread', 'most_unread', 'warnings']);
	}

	/**
	* An object schema, so that the nesting is written once instead of brace by brace.
	*
	* @param array<string,mixed> $properties
	* @param list<string> $required
	* @return array<string,mixed>
	*/
	private static function shape(array $properties, array $required): array {
		return ['type' => 'object', 'properties' => $properties, 'required' => $required];
	}

	/**
	* The fields every entry carries, whether in a list or on its own.
	*
	* @return array<string, array<string,mixed>>
	*/
	private static function entrySchema(): array {
		return [
			'id' => ['type' => 'string', 'description' => 'The FreshRSS entry id, what get_article takes.'],
			'title' => ['type' => 'string'],
			'link' => ['type' => 'string', 'description' => 'The article at the publisher.'],
			'feed_id' => ['type' => 'integer'],
			'feed' => ['type' => ['string', 'null'], 'description' => 'Feed title, null when the feed is gone.'],
			'published' => ['type' => 'string', 'description' => 'Publication time, RFC 2822.'],
			'unread' => ['type' => 'boolean'],
			'starred' => ['type' => 'boolean'],
			'content' => ['type' => 'string', 'description' => 'Body as plain text, only when include_content was set.'],
		];
	}

	/**
	 * Refuse arguments the tool does not take.
	 *
	 * @param array<string,mixed> $arguments
	 */
	private static function checkArguments(string $name, array $arguments): void {
		$properties = self::specs()[$name]['inputSchema']['properties'] ?? [];
		$known = is_array($properties) ? array_keys($properties) : [];
		$unknown = array_diff(array_map('strval', array_keys($arguments)), $known);
		if ($unknown === []) {
			return;
		}

		throw new InvalidArgumentException("{$name} has no argument named " . implode(', ', $unknown) . '. It takes ' .
			($known === [] ? 'no arguments at all.' : implode(', ', $known) . '.'));
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

		return ['categories' => $categories, 'feeds' => $feedCount,
			'unread' => FreshRSS_Category::countUnread(self::categories())];
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
		$refusal = FreshMcp_Query::refusal($query);
		if ($refusal !== null) {
			throw new InvalidArgumentException('That search was not carried out: ' . $refusal);
		}

		// Whether to keep the unread ones is a state of the entry, so it is asked of the database and
		// not written into the query. There is no `is:unread` operator, and inventing one leaves the
		// words to be searched for as text, which matches nothing.
		return self::entries($arguments + ['search' => $query], self::boolArg($arguments, 'unread_only', false));
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
		$categoryId = self::intOpt($arguments, 'category_id');

		$booleanSearch = new FreshRSS_BooleanSearch($search);
		$extra = new FreshRSS_Search('');
		$since = self::dateArg($arguments, 'since');
		$until = self::dateArg($arguments, 'until');
		if ($since !== null) {
			$extra->setMinDate($since);
		}
		if ($until !== null) {
			$extra->setMaxDate($until);
		}
		if ($since !== null && $until !== null && $since > $until) {
			throw new InvalidArgumentException('since is after until, so no entry could satisfy both.');
		}
		if ($since !== null || $until !== null) {
			$booleanSearch->add($extra);
		}

		if ($feedId !== null && $categoryId !== null) {
			throw new InvalidArgumentException('Give feed_id or category_id, not both: a feed already belongs to '
				. 'one category, so the two can only agree or exclude everything.');
		}

		// A feed narrows the query, a category narrows the SQL join; both keep any search terms.
		$type = 'A';
		$id = 0;
		if ($feedId !== null) {
			$type = 'f';
			$id = $feedId;
		} elseif ($categoryId !== null) {
			$type = 'c';
			$id = $categoryId;
		}

		$state = $unreadOnly ? FreshRSS_Entry::STATE_NOT_READ : FreshRSS_Entry::STATE_ALL;
		$resumed = self::stringArg($arguments, 'continuation', '') !== '';
		$continuationId = '0';
		$continuationValues = [];
		if ($resumed) {
			$parsed = FreshMcp_Continuation::parse(self::stringArg($arguments, 'continuation', ''), $sort);
			if ($parsed === null) {
				throw new InvalidArgumentException('That continuation token was not issued by this server for this '
					. 'account and this sort order. Ask for the first page again with no continuation.');
			}
			[$continuationId, $continuationValues] = $parsed;
		}

		// Ask for more rows than we return: the extra one says whether there is a next page. With a
		// continuation, FreshRSS starts at the entry the token names, which the client has already
		// seen, so one more row is needed on top of that.
		$entryDAO = FreshRSS_Factory::createEntryDao();
		$rows = iterator_to_array($entryDAO->listWhere($type, $id, $state, $booleanSearch,
			sort: $sort, order: 'DESC', continuation_id: $continuationId, continuation_values: $continuationValues,
			limit: $limit + ($resumed ? 2 : 1)), false);

		// Drop the token's own entry only when it actually came back. It does not when it was marked
		// read between two pages of an unread queue, or purged, and the first row is then one the
		// client has never seen.
		if ($resumed && $rows !== [] && (string) $rows[0]->id() === $continuationId) {
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
			$result['continuation'] = FreshMcp_Continuation::make(end($rows), $sort);
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

		if ($id !== '' && ($feedId !== null || $guid !== '')) {
			throw new InvalidArgumentException('Give get_article an id, or feed_id and guid, not both.');
		}

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
			self::fetchFullContent($entry);
		}

		$result = self::entrySummary($entry, self::feedTitles());
		$authors = $entry->authors(true);
		if ($authors !== '') {
			$result['author'] = $authors;
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
	 * Ask the publisher for the page, for feeds that only deliver a teaser, and keep the result in
	 * memory only: nothing is written back to the database.
	 *
	 * FreshRSS extracts the article with the feed's own CSS selector, so without one there is nothing
	 * it can do. It has to be forced, too: unforced, FreshRSS finds the entry already stored and only
	 * reloads what it has. The URL comes from the feed, so it is checked before anything connects to
	 * it; FreshRSS then checks every redirection again itself and pins the connection to the
	 * addresses it checked.
	 */
	private static function fetchFullContent(FreshRSS_Entry $entry): void {
		$feed = $entry->feed();
		if ($feed === null || trim($feed->pathEntries()) === '') {
			throw new InvalidArgumentException('This feed has no article CSS selector set in FreshRSS (feed settings, '
				. 'Advanced), so FreshRSS cannot extract the full article from the page. Leave fetch_full_content off '
				. 'and use the text the feed sent, or set the selector in FreshRSS first.');
		}

		$refusal = FreshMcp_UrlPolicy::refusal(htmlspecialchars_decode($entry->link(), ENT_QUOTES));
		if ($refusal !== null) {
			throw new InvalidArgumentException($refusal);
		}
		if (!$entry->loadCompleteContent(true)) {
			throw new InvalidArgumentException('FreshRSS could not retrieve the full article: the page did not load, '
				. 'or the feed\'s CSS selector matched nothing on it. Leave fetch_full_content off to read the stored text.');
		}
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
		$starredAll = (int) ($starred['all'] ?? -1);
		$starredUnread = (int) ($starred['unread'] ?? -1);

		// FreshRSS answers -1 when the count failed. A failed count is not the same as an empty one,
		// so those fields go null and the reason is said rather than a confident wrong number.
		$warnings = [];
		if ($starredAll < 0 || $starredUnread < 0) {
			$warnings[] = 'the starred counts could not be read from the database; they are null, not zero';
		}

		return [
			'user' => Minz_User::name(),
			'categories' => count($categories),
			'feeds' => count($feeds),
			'unread' => FreshRSS_Category::countUnread($categories),
			'entries_stored' => $entryDAO->count(),
			'starred' => $starredAll < 0 ? null : $starredAll,
			'starred_unread' => $starredUnread < 0 ? null : $starredUnread,
			'most_unread' => array_slice($noisiest, 0, 10),
			'warnings' => $warnings,
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

	private static function sort(array $arguments): string {
		$sort = self::stringArg($arguments, 'sort', 'date');
		if (!in_array($sort, self::SORTS, true)) {
			throw new InvalidArgumentException('sort must be one of: ' . implode(', ', self::SORTS) . '.');
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
		$user = (string) Minz_User::name();
		if (!isset(self::$categories[$user])) {
			self::$categories[$user] = array_values(FreshRSS_Factory::createCategoryDao()->listCategories(true, false));
		}

		return self::$categories[$user];
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	private static function success(string $name, array $data): array {
		// The same answer twice, on purpose. `content` is the text a model reads; `structuredContent`
		// is what a program checks against outputSchema. A client that wants one of them can ignore the
		// other, and only a client that reads both pays for both.
		return [
			'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
			'structuredContent' => $data,
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

	/**
	 * A Unix timestamp bound.
	 *
	 * A date in the twenty-fifth century is a mistake far more often than a request, and answering it
	 * with an empty list leaves the caller wondering whether the filter worked at all.
	 *
	 * @param array<string,mixed> $arguments
	 */
	private static function dateArg(array $arguments, string $name): ?int {
		$value = self::intOpt($arguments, $name);
		if ($value === null) {
			return null;
		}
		if ($value < 0 || $value > self::MAX_TIMESTAMP) {
			throw new InvalidArgumentException("{$name} must be a Unix timestamp between 0 and " . self::MAX_TIMESTAMP
				. ' (up to ' . gmdate('Y-m-d', self::MAX_TIMESTAMP) . ').');
		}

		return $value;
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
