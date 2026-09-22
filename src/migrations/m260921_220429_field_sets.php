<?php

namespace fostercommerce\variantmanager\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use fostercommerce\variantmanager\db\Table;

/**
 * Move each attribute's field layouts into a field set that any attribute can be assigned.
 */
class m260921_220429_field_sets extends Migration
{
	private const OLD_CONFIG_PATH = 'variant-manager.attributes';

	private const NEW_CONFIG_PATH = 'variant-manager.fieldSets';

	public function safeUp(): bool
	{
		$this->addColumn(Table::ATTRIBUTES, 'fieldSetUid', $this->char(36)->null()->after('priceModifier'));
		$this->createIndex(null, Table::ATTRIBUTES, ['fieldSetUid'], false);

		$projectConfig = Craft::$app->getProjectConfig();
		$configs = $projectConfig->get(self::OLD_CONFIG_PATH);

		// A deploy that applies project config before migrating has the field sets already, keyed on the same uid
		if (! is_array($configs)) {
			$configs = $projectConfig->get(self::NEW_CONFIG_PATH);
		}

		if (! is_array($configs)) {
			return true;
		}

		$canonicals = (new Query())
			->select(['elements.uid', 'elements.id', 'attributes.name'])
			->from([
				'attributes' => Table::ATTRIBUTES,
			])
			->innerJoin([
				'elements' => CraftTable::ELEMENTS,
			], '[[elements.id]] = [[attributes.id]]')
			->where([
				'attributes.attributeId' => 0,
				'elements.draftId' => null,
				'elements.revisionId' => null,
			])
			->all();

		$canonicalsByUid = array_column($canonicals, null, 'uid');


		foreach ($configs as $attributeUid => $config) {
			$canonical = $canonicalsByUid[$attributeUid] ?? null;

			if ($canonical === null) {
				continue;
			}

			$name = (string) $canonical['name'];

			// Drafts and revisions too. Applying a draft writes its own row back over the canonical.
			$derivativeIds = (new Query())
				->select(['id'])
				->from(CraftTable::ELEMENTS)
				->where([
					'canonicalId' => $canonical['id'],
				])
				->column();

			// The field set reuses the attribute uid, so a read-only install maps rows without writing config
			$this->update(Table::ATTRIBUTES, [
				'fieldSetUid' => $attributeUid,
			], [
				'id' => [$canonical['id'], ...$derivativeIds],
			]);

			if ($projectConfig->readOnly || $projectConfig->get(self::NEW_CONFIG_PATH . '.' . $attributeUid) !== null) {
				continue;
			}

			$projectConfig->set(self::NEW_CONFIG_PATH . '.' . $attributeUid, [
				'name' => $name,
				'fieldLayouts' => $config['fieldLayouts'] ?? [],
				'optionFieldLayouts' => $config['optionFieldLayouts'] ?? [],
			], "Move the “{$name}” variant attribute settings into a field set");
		}

		if (! $projectConfig->readOnly) {
			$projectConfig->remove(self::OLD_CONFIG_PATH, 'Remove the per-attribute variant attribute settings');
		}

		return true;
	}
}
