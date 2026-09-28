<?php

namespace fostercommerce\variantmanager\migrations;

use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Variant;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\queue\jobs\UpdateSearchIndex;
use fostercommerce\variantmanager\db\Table;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\Plugin;
use yii\db\Expression;

/**
 * m260928_143541_clean_attribute_names migration.
 */
class m260928_143541_clean_attribute_names extends Migration
{
	public function safeUp(): bool
	{
		// Skip drafts and revisions, because they hold their uid as the key
		/** @var list<array{id: int|string, attributeId: int|string, name: string, nameKey: string}> $rows */
		$rows = (new Query())
			->select(['attributes.id', 'attributes.attributeId', 'attributes.name', 'attributes.nameKey'])
			->from([
				'attributes' => Table::ATTRIBUTES,
			])
			->innerJoin([
				'elements' => CraftTable::ELEMENTS,
			], '[[elements.id]] = [[attributes.id]]')
			->where([
				'elements.draftId' => null,
				'elements.revisionId' => null,
			])
			->all($this->db);

		$takenKeys = [];

		foreach ($rows as $row) {
			$takenKeys["{$row['attributeId']}\0{$row['nameKey']}"] = true;
		}

		foreach ($rows as $row) {
			$name = VariantAttribute::cleanName($row['name']);
			$nameKey = VariantAttribute::normalizeName($name);

			if ($name === $row['name'] && $nameKey === $row['nameKey']) {
				continue;
			}

			// Leave a record whose cleaned key another record already holds, because the unique index would reject it
			if ($nameKey !== $row['nameKey'] && isset($takenKeys["{$row['attributeId']}\0{$nameKey}"])) {
				// Escape every non-ASCII character, so a no-break or zero-width space shows in the output
				$shownName = Json::encode($row['name'], JSON_UNESCAPED_SLASHES);
				echo "    > kept {$shownName} (id {$row['id']}), because its cleaned name matches another record. Delete it, or move its fields to the other record.\n";
				continue;
			}

			$this->update(Table::ATTRIBUTES, [
				'name' => $name,
				'nameKey' => $nameKey,
			], [
				'id' => $row['id'],
			]);

			$takenKeys["{$row['attributeId']}\0{$nameKey}"] = true;
		}

		$this->cleanStoredPairs();

		return true;
	}

	/**
	 * Rewrite the stored pairs that hold a character cleanName() replaces, so variants match the cleaned registry.
	 *
	 * Write the content rows directly, as Craft's migrations do, rather than save elements and run their event handlers.
	 */
	private function cleanStoredPairs(): void
	{
		// Match control characters in their escaped JSON form, and DEL, C1 controls and separators raw
		$fragments = ['\\t', '\\n', '\\r', '\\b', '\\f', '\\u00', '\\u2028', '\\u2029', "\u{1680}", "\u{2028}", "\u{2029}", "\u{202F}", "\u{205F}", "\u{3000}", "\u{FEFF}"];

		foreach ([...range(0x7F, 0xA0), ...range(0x2000, 0x200B)] as $codePoint) {
			$fragments[] = mb_chr($codePoint, 'UTF-8');
		}

		// Match a plain space next to a value's quotes, in the spaced form the databases print and the compact form PHP writes
		$fragments = [...$fragments, ': " ', '":" ', ' ",', ' "}'];

		$fields = Plugin::getInstance()->getVariantAttributes()->getVariantAttributesFields();
		$fieldsByUid = [];
		$fieldHandles = [];
		$condition = ['or'];

		foreach ($fields as $field) {
			$valueSql = $field->getValueSql();

			if ($valueSql === null) {
				continue;
			}

			$fieldsByUid[(string) $field->layoutElement?->uid] = $field;
			$fieldHandles[(string) $field->handle] = (string) $field->handle;

			foreach ($fragments as $fragment) {
				$condition[] = ['like', new Expression($valueSql), $fragment];
			}
		}

		if ($fieldsByUid === []) {
			return;
		}

		$rowQuery = (new Query())
			->select(['elements_sites.id', 'elements_sites.elementId', 'elements_sites.siteId', 'elements_sites.content'])
			->from([
				'elements_sites' => CraftTable::ELEMENTS_SITES,
			])
			->innerJoin([
				'variants' => CommerceTable::VARIANTS,
			], '[[variants.id]] = [[elements_sites.elementId]]')
			->where($condition);

		$cleanedIdsBySiteId = [];

		// Read in batches, because a case-insensitive collation can match many rows the cleanup leaves unchanged
		$rows = Db::each($rowQuery);

		foreach ($rows as $row) {
			/** @var array{id: int|string, elementId: int|string, siteId: int|string, content: string|null} $row */
			$content = Json::decode((string) $row['content']);

			if (! is_array($content)) {
				continue;
			}

			$cleanedContent = $content;

			foreach ($fieldsByUid as $fieldUid => $field) {
				if (isset($content[$fieldUid])) {
					$cleanedContent[$fieldUid] = $field->serializeValue($content[$fieldUid]);
				}
			}

			if ($cleanedContent === $content) {
				continue;
			}

			Db::update(CraftTable::ELEMENTS_SITES, [
				'content' => $cleanedContent,
			], [
				'id' => $row['id'],
			], updateTimestamp: false, db: $this->db);
			$cleanedIdsBySiteId[(int) $row['siteId']][] = (int) $row['elementId'];
		}

		// Reindex the rewritten values, because the rewrite skips the save that would update search keywords
		foreach ($cleanedIdsBySiteId as $siteId => $elementIds) {
			Queue::push(new UpdateSearchIndex([
				'elementType' => Variant::class,
				'elementId' => $elementIds,
				'siteId' => $siteId,
				'fieldHandles' => array_values($fieldHandles),
			]));
		}

		$cleanedCount = array_sum(array_map(count(...), $cleanedIdsBySiteId));

		if ($cleanedCount > 0) {
			echo "    > cleaned stored names on {$cleanedCount} variant " . ($cleanedCount === 1 ? 'row' : 'rows') . "\n";
		}
	}
}
