<?php

namespace fostercommerce\variantmanager\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use fostercommerce\variantmanager\db\Table;

/**
 * Key attribute settings on the attribute uid rather than its name key.
 *
 * A name key holding a period was stored nested, and the orphan sweep deleted the branch.
 */
class m260920_120000_key_attribute_configs_on_uid extends Migration
{
	// The path these settings used before the field set migration moved them
	private const CONFIG_PATH = 'variant-manager.attributes';

	public function safeUp(): bool
	{
		$projectConfig = Craft::$app->getProjectConfig();

		// ProjectConfig::set() throws NotSupportedException in read-only mode
		if ($projectConfig->readOnly) {
			return true;
		}

		$uidsByNameKey = (new Query())
			->select(['attributes.nameKey', 'elements.uid'])
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
			->pairs();

		$migrated = [];

		foreach ($uidsByNameKey as $nameKey => $attributeUid) {
			// Read on the old path. A period in the name key splits the same way it did on write.
			$config = $projectConfig->get(self::CONFIG_PATH . '.' . $nameKey);

			if (! is_array($config)) {
				continue;
			}

			// A name key whose path segments prefix another one reads back with that one nested inside it
			$layouts = array_intersect_key($config, array_flip(['fieldLayouts', 'optionFieldLayouts']));

			if ($layouts !== []) {
				$projectConfig->set(
					self::CONFIG_PATH . '.' . $attributeUid,
					$layouts,
					"Key the “{$nameKey}” variant attribute settings on its uid"
				);

				$migrated[] = $nameKey;
			}
		}

		// Remove after every write, because removing a name key takes the keys nested under it too
		foreach ($migrated as $nameKey) {
			$projectConfig->remove(self::CONFIG_PATH . '.' . $nameKey);
		}

		return true;
	}
}
