<?php
declare(strict_types=1);

/**
 * Labels (FreshRSS calls them tags in code): the user's own groupings of entries.
 *
 * Labels and categories share one namespace in FreshRSS; the DAOs refuse a clash by quietly
 * inserting nothing. assertNameFree() checks first, so that the caller is told which name is taken
 * and by what, instead of reading a failure it cannot explain.
 */
final class FreshMcp_LabelTools {

	/** @return array<string, array<string,mixed>> */
	public static function specs(): array {
		$label = ['type' => 'object', 'properties' => ['label' => self::labelSchema()], 'required' => ['label']];
		$write = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false,
			'openWorldHint' => false];

		return [
			'list_labels' => [
				'title' => 'List labels',
				'description' => 'Every label of the account with how many entries carry it and how many of those are '
					. 'unread. search takes L:<id> to list the entries of one label.',
				'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
				'outputSchema' => FreshMcp_Tools::shape([
					'labels' => ['type' => 'array', 'items' => self::labelSchema()],
				], ['labels']),
				'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
			],
			'create_label' => [
				'title' => 'Create a label',
				'description' => 'Create an empty label. label_entries also creates one on first use, so this is only '
					. 'needed to prepare a label in advance. Labels and categories may not share a name.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'name' => ['type' => 'string', 'description' => 'The new label name.'],
				], 'required' => ['name']],
				'outputSchema' => $label,
				'annotations' => $write,
			],
			'rename_label' => [
				'title' => 'Rename a label',
				'description' => 'Give a label a new name. Refused when the name is already used by another label or by '
					. 'a category.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'label_id' => ['type' => 'integer', 'description' => 'The label (see list_labels).'],
					'name' => ['type' => 'string', 'description' => 'The new name.'],
				], 'required' => ['label_id', 'name']],
				'outputSchema' => $label,
				'annotations' => ['idempotentHint' => true] + $write,
			],
			'delete_label' => [
				'title' => 'Delete a label',
				'description' => 'Delete a label and take it off every entry that carries it. The entries themselves '
					. 'are kept.',
				'inputSchema' => ['type' => 'object', 'properties' => [
					'label_id' => ['type' => 'integer', 'description' => 'The label (see list_labels).'],
				], 'required' => ['label_id']],
				'outputSchema' => FreshMcp_Tools::shape([
					'deleted' => self::labelSchema(),
				], ['deleted']),
				'annotations' => ['destructiveHint' => true, 'idempotentHint' => true] + $write,
			],
		];
	}

	/**
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	public static function run(string $name, array $arguments): array {
		$tagDAO = FreshRSS_Factory::createTagDao();
		switch ($name) {
			case 'list_labels':
				$labels = [];
				foreach ($tagDAO->listTags(precounts: true) as $tag) {
					$labels[] = self::row($tag);
				}
				return ['labels' => $labels];
			case 'create_label':
				return ['label' => self::row(self::create(FreshMcp_Arguments::name($arguments, 'name')))];
			case 'rename_label':
				$tag = self::find(FreshMcp_Arguments::id($arguments, 'label_id'));
				$newName = FreshMcp_Arguments::name($arguments, 'name');
				if ($newName !== $tag->name()) {
					self::assertNameFree($newName);
					if ($tagDAO->updateTagName($tag->id(), $newName) === false) {
						throw new RuntimeException('updateTagName failed');
					}
				}
				return ['label' => self::row(self::find($tag->id()))];
			case 'delete_label':
				$tag = self::find(FreshMcp_Arguments::id($arguments, 'label_id'));
				// Read the counts before they are gone, so the answer says what was removed.
				$row = self::row($tag);
				// The entry links go with the label: the schema cascades the delete.
				if ($tagDAO->deleteTag($tag->id()) === false) {
					throw new RuntimeException('deleteTag failed');
				}
				return ['deleted' => $row];
		}
		throw new LogicException("FreshMcp_LabelTools does not own the tool {$name}");
	}

	/** @throws InvalidArgumentException when the account has no such label */
	public static function find(int $id): FreshRSS_Tag {
		return FreshRSS_Factory::createTagDao()->searchById($id) ??
			throw new InvalidArgumentException("There is no label {$id} in this account; see list_labels.");
	}

	/**
	 * Create a label under a name already in stored (escaped) form; see FreshMcp_Arguments::nameOpt().
	 */
	public static function create(string $name): FreshRSS_Tag {
		self::assertNameFree($name);
		$tagDAO = FreshRSS_Factory::createTagDao();
		$id = $tagDAO->addTag(['name' => $name]);
		if ($id === false) {
			throw new RuntimeException('addTag failed');
		}

		return self::find($id);
	}

	/**
	 * Refuse a name that a label or a category already has: FreshRSS keeps the two in one namespace.
	 */
	public static function assertNameFree(string $name): void {
		if (FreshRSS_Factory::createTagDao()->searchByName($name) !== null) {
			throw new InvalidArgumentException("There is already a label named {$name}; see list_labels.");
		}
		if (FreshRSS_Factory::createCategoryDao()->searchByName($name) !== null) {
			throw new InvalidArgumentException("There is already a category named {$name}, and FreshRSS does not let a "
				. 'label and a category share a name.');
		}
	}

	/** @return array<string,mixed> */
	public static function row(FreshRSS_Tag $tag): array {
		return ['id' => $tag->id(), 'name' => $tag->name(), 'entries' => $tag->nbEntries(), 'unread' => $tag->nbUnread()];
	}

	/** @return array<string,mixed> */
	public static function labelSchema(): array {
		return FreshMcp_Tools::shape([
			'id' => ['type' => 'integer'],
			'name' => ['type' => 'string'],
			'entries' => ['type' => 'integer', 'description' => 'Entries carrying the label.'],
			'unread' => ['type' => 'integer', 'description' => 'Of those, how many are unread.'],
		], ['id', 'name', 'entries', 'unread']);
	}
}
