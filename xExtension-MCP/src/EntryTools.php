<?php
declare(strict_types=1);

/**
 * Tools that change the state of entries: read, starred, and the labels they carry.
 *
 * Every write goes through the same FreshRSS DAO calls the web reader and the Google Reader API
 * make, so the unread counters, the lastUserModified stamps and the EntriesRead / EntriesFavorite
 * hooks of other extensions behave exactly as they would for a click in the browser.
 *
 * Ids are checked before anything is written: an id that is not an entry of this account is
 * reported back under not_found rather than silently skipped, and the counts in an answer are the
 * entries whose state actually changed, read from the entries themselves, so that they mean the same
 * thing on SQLite, MySQL and PostgreSQL.
 */
final class FreshMcp_EntryTools {
	/** Ids per call: enough for a page of get_unread or search twice over. */
	private const MAX_IDS = 200;

	/** @return array<string, array<string,mixed>> */
	public static function specs(): array {
		$ids = ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => self::MAX_IDS,
			'description' => 'Entry ids as returned by get_unread or search, at most ' . self::MAX_IDS . '.'];
		$changed = FreshMcp_Tools::shape([
			'changed' => ['type' => 'integer', 'description' => 'Entries whose state actually changed; the others '
				. 'already were that way.'],
			'not_found' => ['type' => 'array', 'items' => ['type' => 'string'],
				'description' => 'Ids that are not entries of this account, perhaps purged. Nothing was done to them.'],
		], ['changed', 'not_found']);
		// Undoable one entry at a time, by the call with the flag the other way round.
		$reversible = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true,
			'openWorldHint' => false];

		return [
			'mark_read' => [
				'title' => 'Mark entries read or unread',
				'description' => 'Mark the given entries read, or unread again with read: false.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'ids' => $ids,
					'read' => ['type' => 'boolean', 'description' => 'true (default) marks read, false marks unread.'],
				], 'required' => ['ids']],
				'outputSchema' => $changed,
				'annotations' => $reversible,
			],
			'set_starred' => [
				'title' => 'Star or unstar entries',
				'description' => 'Star (favourite) the given entries, or unstar them with starred: false.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'ids' => $ids,
					'starred' => ['type' => 'boolean', 'description' => 'true (default) stars, false unstars.'],
				], 'required' => ['ids']],
				'outputSchema' => $changed,
				'annotations' => $reversible,
			],
			'mark_all_read' => [
				'title' => 'Mark everything in a scope read',
				'description' => 'Mark every unread entry of one feed, one category, one label, or the whole account '
					. 'read. Give exactly one of feed_id, category_id, label_id or all: true. Pass up_to_id, the '
					. 'newest entry id you have seen, so that entries arriving meanwhile stay unread. This cannot be '
					. 'undone as a whole: prefer mark_read with ids when the set is small.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'feed_id' => ['type' => 'integer', 'description' => 'One feed (see list_feeds).'],
					'category_id' => ['type' => 'integer', 'description' => 'One category (see list_feeds).'],
					'label_id' => ['type' => 'integer', 'description' => 'One label (see list_labels).'],
					'all' => ['type' => 'boolean', 'description' => 'Every feed get_unread covers, i.e. all but '
						. 'those whose visibility is feed or hidden. Must be true to take effect.'],
					'up_to_id' => ['type' => 'string', 'description' => 'Only entries with this id or older. '
						. 'Defaults to now.'],
				]],
				'outputSchema' => FreshMcp_Tools::shape([
					'marked' => ['type' => 'integer', 'description' => 'Entries that went from unread to read.'],
					'scope' => ['type' => 'string', 'description' => 'What was marked, e.g. feed 3.'],
					'up_to_id' => ['type' => 'string', 'description' => 'The newest id that was eligible.'],
				], ['marked', 'scope', 'up_to_id']),
				'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true,
					'openWorldHint' => false],
			],
			'label_entries' => [
				'title' => 'Add or remove a label on entries',
				'description' => 'Put a label on the given entries, or take it off with remove: true. Name the label '
					. 'with label (its name; created when it does not exist yet and remove is false) or label_id.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'ids' => $ids,
					'label' => ['type' => 'string', 'description' => 'Label name, e.g. "to read".'],
					'label_id' => ['type' => 'integer', 'description' => 'Label id (see list_labels).'],
					'remove' => ['type' => 'boolean', 'description' => 'true takes the label off instead.'],
				], 'required' => ['ids']],
				'outputSchema' => FreshMcp_Tools::shape([
					'label' => FreshMcp_LabelTools::labelSchema(),
					'created' => ['type' => 'boolean', 'description' => 'Whether the label was created by this call.'],
				] + $changed['properties'], ['label', 'created', 'changed', 'not_found']),
				'annotations' => $reversible,
			],
		];
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	public static function run(string $name, array $arguments): array {
		return match ($name) {
			'mark_read' => self::markRead($arguments),
			'set_starred' => self::setStarred($arguments),
			'mark_all_read' => self::markAllRead($arguments),
			'label_entries' => self::labelEntries($arguments),
		};
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function markRead(array $arguments): array {
		$read = FreshMcp_Arguments::bool($arguments, 'read', true);
		[$entries, $notFound] = self::entries(FreshMcp_Arguments::entryIds($arguments, 'ids', self::MAX_IDS));
		$targets = array_keys(array_filter($entries, static fn(FreshRSS_Entry $e): bool => $e->isRead() !== $read));
		if ($targets !== [] && FreshRSS_Factory::createEntryDao()->markRead(self::numeric($targets), $read) === false) {
			throw new RuntimeException('markRead failed');
		}

		return ['changed' => count($targets), 'not_found' => $notFound];
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function setStarred(array $arguments): array {
		$starred = FreshMcp_Arguments::bool($arguments, 'starred', true);
		[$entries, $notFound] = self::entries(FreshMcp_Arguments::entryIds($arguments, 'ids', self::MAX_IDS));
		$targets = array_keys(array_filter($entries,
			static fn(FreshRSS_Entry $e): bool => $e->isFavorite() !== $starred));
		if ($targets !== [] && FreshRSS_Factory::createEntryDao()->markFavorite(self::numeric($targets), $starred) === false) {
			throw new RuntimeException('markFavorite failed');
		}

		return ['changed' => count($targets), 'not_found' => $notFound];
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function markAllRead(array $arguments): array {
		$feedId = FreshMcp_Arguments::idOpt($arguments, 'feed_id');
		$categoryId = FreshMcp_Arguments::idOpt($arguments, 'category_id');
		$labelId = FreshMcp_Arguments::idOpt($arguments, 'label_id');
		$all = FreshMcp_Arguments::bool($arguments, 'all', false);
		$scopes = count(array_filter([$feedId !== null, $categoryId !== null, $labelId !== null, $all]));
		if ($scopes !== 1) {
			throw new InvalidArgumentException('Give exactly one of feed_id, category_id, label_id or all: true, so that '
				. 'what gets marked read is never a guess.');
		}

		$upTo = FreshMcp_Arguments::string($arguments, 'up_to_id', '');
		if ($upTo === '') {
			$upTo = uTimeString();
		} elseif (!ctype_digit($upTo) || strlen($upTo) > 19) {
			throw new InvalidArgumentException('up_to_id must be an entry id, the decimal string get_unread returns.');
		}

		$entryDAO = FreshRSS_Factory::createEntryDao();
		if ($feedId !== null) {
			if (FreshRSS_Factory::createFeedDao()->searchById($feedId) === null) {
				throw new InvalidArgumentException("There is no feed {$feedId} in this account; see list_feeds.");
			}
			$scope = "feed {$feedId}";
			$marked = $entryDAO->markReadFeed($feedId, $upTo);
		} elseif ($categoryId !== null) {
			if (FreshRSS_Factory::createCategoryDao()->searchById($categoryId) === null) {
				throw new InvalidArgumentException("There is no category {$categoryId} in this account; see list_feeds.");
			}
			$scope = "category {$categoryId}";
			$marked = $entryDAO->markReadCat($categoryId, $upTo);
		} elseif ($labelId !== null) {
			FreshMcp_LabelTools::find($labelId);
			$scope = "label {$labelId}";
			$marked = $entryDAO->markReadTag($labelId, $upTo);
		} else {
			// The same feeds get_unread lists: visibility category and up; feed-only and hidden ones are left.
			$scope = 'all';
			$marked = $entryDAO->markReadEntries($upTo, priorityMin: FreshRSS_Feed::PRIORITY_CATEGORY);
		}
		if ($marked === false) {
			throw new RuntimeException("marking {$scope} read failed");
		}

		return ['marked' => $marked, 'scope' => $scope, 'up_to_id' => $upTo];
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function labelEntries(array $arguments): array {
		$ids = FreshMcp_Arguments::entryIds($arguments, 'ids', self::MAX_IDS);
		$remove = FreshMcp_Arguments::bool($arguments, 'remove', false);
		$labelName = FreshMcp_Arguments::nameOpt($arguments, 'label');
		$labelId = FreshMcp_Arguments::idOpt($arguments, 'label_id');
		if (($labelName === null) === ($labelId === null)) {
			throw new InvalidArgumentException('Give label (a name) or label_id, exactly one of them.');
		}

		$tagDAO = FreshRSS_Factory::createTagDao();
		$tag = $labelId !== null ? FreshMcp_LabelTools::find($labelId) : $tagDAO->searchByName($labelName ?? '');
		if ($tag === null && $remove) {
			throw new InvalidArgumentException('There is no label named ' . $labelName . ', so there is nothing to '
				. 'remove; see list_labels.');
		}
		[$entries, $notFound] = self::entries($ids);
		$created = $tag === null;
		$tag ??= FreshMcp_LabelTools::create($labelName ?? '');

		// entries() never returns none, which matters: with no ids, FreshRSS answers with every label link.
		$rows = $tagDAO->getTagsForEntries(self::numeric(array_keys($entries)));
		if ($rows === null) {
			throw new RuntimeException('getTagsForEntries failed');
		}
		$tagged = [];
		foreach ($rows as $row) {
			if ((int) $row['id_tag'] === $tag->id()) {
				$tagged[(string) $row['id_entry']] = true;
			}
		}
		$changed = 0;
		foreach (array_keys($entries) as $id) {
			if (isset($tagged[(string) $id]) === $remove) {
				if (!$tagDAO->tagEntry($tag->id(), (string) $id, !$remove)) {
					throw new RuntimeException('tagEntry failed');
				}
				$changed++;
			}
		}

		return ['label' => FreshMcp_LabelTools::row($tag), 'created' => $created, 'changed' => $changed,
			'not_found' => $notFound];
	}

	/**
	 * The entries of this account among those ids, and the ids that are not.
	 *
	 * Some ids missing is an answer (not_found); all of them missing is a mistake in the call, and is
	 * refused before anything, a new label included, is written.
	 *
	 * @param non-empty-list<numeric-string> $ids
	 * @return array{0: non-empty-array<string,FreshRSS_Entry>, 1: list<string>} entries keyed by id, missing ids
	 */
	private static function entries(array $ids): array {
		$entries = [];
		foreach (FreshRSS_Factory::createEntryDao()->listByIds($ids) as $entry) {
			$entries[(string) $entry->id()] = $entry;
		}
		if ($entries === []) {
			throw new InvalidArgumentException('None of those ids is an entry of this account, so nothing was changed. '
				. 'Use the ids get_unread or search return.');
		}
		$notFound = array_values(array_filter($ids, static fn(string $id): bool => !isset($entries[$id])));

		return [$entries, $notFound];
	}

	/**
	 * Array keys that look like numbers come back from PHP as integers; FreshRSS wants the strings.
	 *
	 * @param list<int|string> $ids
	 * @return list<numeric-string>
	 */
	private static function numeric(array $ids): array {
		/** @var list<numeric-string> */
		return array_map('strval', $ids);
	}
}
