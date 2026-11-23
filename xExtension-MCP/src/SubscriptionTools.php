<?php
declare(strict_types=1);

/**
 * Feeds and categories: subscribing, organising, refreshing and unsubscribing.
 *
 * Feed changes go through FreshRSS_feed_Controller, the code path of the web interface and of the
 * Google Reader API, so limits (max_feeds, max_categories), extension hooks (CheckUrlBeforeAdd,
 * FeedBeforeInsert, FeedBeforeActualize…) and the cleanup of saved searches all apply as they do
 * for a click in the browser.
 *
 * subscribe_feed and refresh_feed make FreshRSS fetch a URL. That fetch is FreshRSS' own, with its
 * own SSRF rules: private, loopback and link-local addresses are refused unless the administrator
 * listed them in internal_host_allowlist, which is the same policy the web interface applies to a
 * subscription. No extra check sits in front of it on purpose: an allowlisted RSS-Bridge on the LAN
 * has to work here exactly as it does in the browser.
 */
final class FreshMcp_SubscriptionTools {
	/** Feed visibility, by the names the web interface uses, and the FreshRSS priority each means. */
	private const VISIBILITY = [
		'important' => FreshRSS_Feed::PRIORITY_IMPORTANT,
		'main' => FreshRSS_Feed::PRIORITY_MAIN_STREAM,
		'category' => FreshRSS_Feed::PRIORITY_CATEGORY,
		'feed' => FreshRSS_Feed::PRIORITY_FEED,
		'hidden' => FreshRSS_Feed::PRIORITY_HIDDEN,
	];

	/** @return array<string, array<string,mixed>> */
	public static function specs(): array {
		$feedOut = ['type' => 'object', 'properties' => ['feed' => self::feedSchema()], 'required' => ['feed']];
		$categoryOut = ['type' => 'object', 'properties' => ['category' => self::categorySchema()],
			'required' => ['category']];
		$local = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true,
			'openWorldHint' => false];
		$visibility = ['type' => 'string', 'enum' => array_keys(self::VISIBILITY), 'description' => 'Where the feed '
			. 'shows: important (also in Important), main (main stream, the default), category (only in its category), '
			. 'feed (only on its own), hidden (nowhere). get_unread without feed_id skips feed and hidden ones.'];

		return [
			'subscribe_feed' => [
				'title' => 'Subscribe to a feed',
				'description' => 'Add an RSS, Atom or JSON Feed by its URL, fetch it once, and return the new feed; '
					. 'its entries count is what that first fetch stored. '
					. 'FreshRSS fetches the URL itself and refuses private network addresses its administrator has '
					. 'not allowed.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'url' => ['type' => 'string', 'description' => 'The feed URL (not the website), http or https.'],
					'category_id' => ['type' => 'integer', 'description' => 'Put it in this category (see list_feeds); '
						. 'default: the default category.'],
					'title' => ['type' => 'string', 'description' => 'A title of your own instead of the feed\'s.'],
				], 'required' => ['url']],
				'outputSchema' => $feedOut,
				'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false,
					'openWorldHint' => true],
			],
			'update_feed' => [
				'title' => 'Rename, move or re-prioritise a feed',
				'description' => 'Change the title, the category or the visibility of a feed. Give at least one.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'feed_id' => ['type' => 'integer', 'description' => 'The feed (see list_feeds).'],
					'title' => ['type' => 'string', 'description' => 'The new title.'],
					'category_id' => ['type' => 'integer', 'description' => 'Move it to this category.'],
					'visibility' => $visibility,
				], 'required' => ['feed_id']],
				'outputSchema' => $feedOut,
				'annotations' => $local,
			],
			'unsubscribe_feed' => [
				'title' => 'Unsubscribe from a feed',
				'description' => 'Delete a feed together with all of its stored entries, starred ones included. This '
					. 'cannot be undone; subscribing again fetches only what the feed still publishes.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'feed_id' => ['type' => 'integer', 'description' => 'The feed (see list_feeds).'],
				], 'required' => ['feed_id']],
				'outputSchema' => FreshMcp_Tools::shape(['deleted' => self::feedSchema()], ['deleted']),
				'annotations' => ['destructiveHint' => true] + $local,
			],
			'refresh_feed' => [
				'title' => 'Refresh a feed now',
				'description' => 'Fetch one feed now instead of waiting for the next scheduled refresh.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'feed_id' => ['type' => 'integer', 'description' => 'The feed (see list_feeds).'],
				], 'required' => ['feed_id']],
				'outputSchema' => FreshMcp_Tools::shape([
					'feed' => self::feedSchema(),
					'new_entries' => ['type' => 'integer', 'description' => 'Entries added by this refresh.'],
				], ['feed', 'new_entries']),
				'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false,
					'openWorldHint' => true],
			],
			'create_category' => [
				'title' => 'Create a category',
				'description' => 'Create an empty category. Categories and labels may not share a name.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'name' => ['type' => 'string', 'description' => 'The new category name.'],
				], 'required' => ['name']],
				'outputSchema' => $categoryOut,
				'annotations' => ['idempotentHint' => false] + $local,
			],
			'rename_category' => [
				'title' => 'Rename a category',
				'description' => 'Give a category a new name. Refused when another category or a label has it.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'category_id' => ['type' => 'integer', 'description' => 'The category (see list_feeds).'],
					'name' => ['type' => 'string', 'description' => 'The new name.'],
				], 'required' => ['category_id', 'name']],
				'outputSchema' => $categoryOut,
				'annotations' => $local,
			],
			'delete_category' => [
				'title' => 'Delete a category',
				'description' => 'Delete a category. Its feeds are kept and move to the default category, which itself '
					. 'cannot be deleted.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'category_id' => ['type' => 'integer', 'description' => 'The category (see list_feeds).'],
				], 'required' => ['category_id']],
				'outputSchema' => FreshMcp_Tools::shape([
					'deleted' => self::categorySchema(),
					'feeds_moved' => ['type' => 'integer', 'description' => 'Feeds now in the default category.'],
				], ['deleted', 'feeds_moved']),
				'annotations' => ['destructiveHint' => true] + $local,
			],
		];
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	public static function run(string $name, array $arguments): array {
		return match ($name) {
			'subscribe_feed' => self::subscribe($arguments),
			'update_feed' => self::updateFeed($arguments),
			'unsubscribe_feed' => self::unsubscribe($arguments),
			'refresh_feed' => self::refresh($arguments),
			'create_category' => self::createCategory($arguments),
			'rename_category' => self::renameCategory($arguments),
			'delete_category' => self::deleteCategory($arguments),
		};
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function subscribe(array $arguments): array {
		$url = FreshMcp_Arguments::string($arguments, 'url', '');
		if (preg_match('#^https?://#i', $url) !== 1) {
			throw new InvalidArgumentException('url must be an http or https feed address.');
		}
		$categoryId = FreshMcp_Arguments::idOpt($arguments, 'category_id');
		if ($categoryId !== null) {
			// FreshRSS would quietly fall back to the default category; say so instead.
			self::category($categoryId);
		}
		$title = FreshMcp_Arguments::nameOpt($arguments, 'title') ?? '';
		// Stored escaped, as the web interface and the Google Reader API store it.
		$url = htmlspecialchars($url, ENT_COMPAT, 'UTF-8');
		// The Google Reader API guesses the same way: nothing else tells JSON Feed and RSS apart up front.
		$kind = preg_match('/(?:\b|_)json(?:\b|_)/i', $url) === 1 ? FreshRSS_Feed::KIND_JSONFEED : FreshRSS_Feed::KIND_RSS;

		try {
			$feed = FreshRSS_feed_Controller::addFeed($url, $title, $categoryId ?? 0, kind: $kind);
		} catch (FreshRSS_AlreadySubscribed_Exception $e) {
			$existing = FreshRSS_Factory::createFeedDao()->searchByUrl($url);
			throw new InvalidArgumentException('Already subscribed to that feed' . ($existing === null ? '' :
				', as feed ' . $existing->id() . ' (' . $existing->name() . ')') . '.');
		} catch (FreshRSS_BadUrl_Exception) {
			throw new InvalidArgumentException('FreshRSS does not accept that URL as a feed address.');
		} catch (FreshRSS_FeedNotAdded_Exception | FreshRSS_Feed_Exception | Minz_FileNotExistException $e) {
			Minz_Log::warning('MCP: subscribe_feed failed: ' . $e::class . ': ' . $e->getMessage());
			throw new InvalidArgumentException('FreshRSS could not subscribe: the address did not answer with a feed it '
				. 'can read, it is on a network FreshRSS may not reach, the feed limit of the account is reached, or an '
				. 'extension refused it. Check the URL is the feed itself, not the website.');
		}

		// A new feed has nothing stored but what this first fetch brought, so its entries count says it.
		return ['feed' => self::feedRow(self::feed($feed->id()))];
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function updateFeed(array $arguments): array {
		$feed = self::feed(FreshMcp_Arguments::id($arguments, 'feed_id'));
		$title = FreshMcp_Arguments::nameOpt($arguments, 'title');
		$categoryId = FreshMcp_Arguments::idOpt($arguments, 'category_id');
		$visibility = FreshMcp_Arguments::string($arguments, 'visibility', '');
		if ($visibility !== '' && !isset(self::VISIBILITY[$visibility])) {
			throw new InvalidArgumentException('visibility must be one of: ' . implode(', ', array_keys(self::VISIBILITY)) . '.');
		}
		if ($title === null && $categoryId === null && $visibility === '') {
			throw new InvalidArgumentException('Give at least one of title, category_id or visibility to change.');
		}
		if ($categoryId !== null) {
			// moveFeed() would quietly fall back to the default category.
			self::category($categoryId);
		}

		if ($title !== null && !FreshRSS_feed_Controller::renameFeed($feed->id(), $title)) {
			throw new RuntimeException('renameFeed failed');
		}
		if ($categoryId !== null && !FreshRSS_feed_Controller::moveFeed($feed->id(), $categoryId)) {
			throw new RuntimeException('moveFeed failed');
		}
		if ($visibility !== '' &&
			!FreshRSS_Factory::createFeedDao()->updateFeed($feed->id(), ['priority' => self::VISIBILITY[$visibility]])) {
			throw new RuntimeException('updateFeed(priority) failed');
		}

		return ['feed' => self::feedRow(self::feed($feed->id()))];
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function unsubscribe(array $arguments): array {
		$feed = self::feed(FreshMcp_Arguments::id($arguments, 'feed_id'));
		// Read before it is gone, so the answer says what was deleted.
		$row = self::feedRow($feed);
		if (!FreshRSS_feed_Controller::deleteFeed($feed->id())) {
			throw new RuntimeException('deleteFeed failed');
		}

		return ['deleted' => $row];
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function refresh(array $arguments): array {
		$feed = self::feed(FreshMcp_Arguments::id($arguments, 'feed_id'));
		[, , $newEntries] = FreshRSS_feed_Controller::actualizeFeedsAndCommit($feed->id());

		$feed = self::feed($feed->id());
		if ($feed->inError()) {
			throw new InvalidArgumentException('FreshRSS could not refresh feed ' . $feed->id() . ': the last fetch failed. '
				. 'The address may be down, no longer a feed, or on a network FreshRSS may not reach.');
		}

		return ['feed' => self::feedRow($feed), 'new_entries' => $newEntries];
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function createCategory(array $arguments): array {
		$name = FreshMcp_Arguments::name($arguments, 'name');
		$categoryDAO = FreshRSS_Factory::createCategoryDao();
		$limit = FreshRSS_Context::systemConf()->limits['max_categories'];
		if ($limit > 0 && $categoryDAO->count() >= $limit) {
			throw new InvalidArgumentException("This account already has the most categories it may have ({$limit}).");
		}
		FreshMcp_LabelTools::assertNameFree($name);
		$id = $categoryDAO->addCategory(['name' => $name]);
		if ($id === false) {
			throw new RuntimeException('addCategory failed');
		}

		return ['category' => self::categoryRow(self::category($id))];
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function renameCategory(array $arguments): array {
		$category = self::category(FreshMcp_Arguments::id($arguments, 'category_id'));
		$name = FreshMcp_Arguments::name($arguments, 'name');
		if ($name !== $category->name()) {
			FreshMcp_LabelTools::assertNameFree($name);
			$updated = FreshRSS_Factory::createCategoryDao()->updateCategory($category->id(),
				['name' => $name, 'kind' => $category->kind(), 'attributes' => $category->attributes()]);
			if ($updated === false) {
				throw new RuntimeException('updateCategory failed');
			}
		}

		return ['category' => self::categoryRow(self::category($category->id()))];
	}

	/**
	 * Same steps as the web interface's category/delete: move the feeds out, delete, forget the saved
	 * searches that pointed at it.
	 *
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private static function deleteCategory(array $arguments): array {
		$category = self::category(FreshMcp_Arguments::id($arguments, 'category_id'));
		if ($category->isDefault()) {
			throw new InvalidArgumentException('The default category cannot be deleted; it is where feeds go when their '
				. 'category is.');
		}
		$row = self::categoryRow($category);

		$moved = FreshRSS_Factory::createFeedDao()->changeCategory($category->id(), FreshRSS_CategoryDAO::DEFAULTCATEGORYID);
		if ($moved === false) {
			throw new RuntimeException('changeCategory failed');
		}
		if (FreshRSS_Factory::createCategoryDao()->deleteCategory($category->id()) === false) {
			throw new RuntimeException('deleteCategory failed');
		}
		$conf = FreshRSS_Context::userConf();
		$conf->queries = FreshRSS_UserQuery::remove_query_by_get('c_' . $category->id(), $conf->queries);
		$conf->save();

		return ['deleted' => $row, 'feeds_moved' => $moved];
	}

	/** @throws InvalidArgumentException when the account has no such feed */
	private static function feed(int $id): FreshRSS_Feed {
		return FreshRSS_Factory::createFeedDao()->searchById($id) ??
			throw new InvalidArgumentException("There is no feed {$id} in this account; see list_feeds.");
	}

	/** @throws InvalidArgumentException when the account has no such category */
	private static function category(int $id): FreshRSS_Category {
		return FreshRSS_Factory::createCategoryDao()->searchById($id) ??
			throw new InvalidArgumentException("There is no category {$id} in this account; see list_feeds.");
	}

	/** @return array<string,mixed> */
	private static function feedRow(FreshRSS_Feed $feed): array {
		return [
			'id' => $feed->id(),
			'title' => $feed->name(),
			'url' => $feed->url(false),
			'site' => $feed->website(true),
			'category_id' => $feed->categoryId(),
			'visibility' => array_search($feed->priority(), self::VISIBILITY, true) ?: (string) $feed->priority(),
			'unread' => $feed->nbNotRead(),
			'entries' => $feed->nbEntries(),
		];
	}

	/** @return array<string,mixed> */
	private static function feedSchema(): array {
		return FreshMcp_Tools::shape([
			'id' => ['type' => 'integer'],
			'title' => ['type' => 'string'],
			'url' => ['type' => 'string', 'description' => 'The feed address FreshRSS pulls from.'],
			'site' => ['type' => 'string', 'description' => 'The publisher website, empty when the feed names none.'],
			'category_id' => ['type' => 'integer'],
			'visibility' => ['type' => 'string', 'description' => 'important, main, category, feed or hidden.'],
			'unread' => ['type' => 'integer'],
			'entries' => ['type' => 'integer', 'description' => 'Entries stored for the feed.'],
		], ['id', 'title', 'url', 'site', 'category_id', 'visibility', 'unread', 'entries']);
	}

	/** @return array<string,mixed> */
	private static function categoryRow(FreshRSS_Category $category): array {
		return [
			'id' => $category->id(),
			'name' => $category->name(),
			'feeds' => $category->nbFeeds(),
			'unread' => $category->nbNotRead(),
		];
	}

	/** @return array<string,mixed> */
	private static function categorySchema(): array {
		return FreshMcp_Tools::shape([
			'id' => ['type' => 'integer'],
			'name' => ['type' => 'string'],
			'feeds' => ['type' => 'integer', 'description' => 'Feeds in the category.'],
			'unread' => ['type' => 'integer', 'description' => 'Unread entries across those feeds.'],
		], ['id', 'name', 'feeds', 'unread']);
	}
}
