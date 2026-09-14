<?php

namespace fostercommerce\variantmanager\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\models\Structure;
use fostercommerce\variantmanager\db\Table;
use fostercommerce\variantmanager\elements\VariantAttribute;

/**
 * Moves option rows into the attributes table as children, so one structure lists both.
 */
class m260914_090000_nest_attribute_options extends Migration
{
	private const OPTIONS_TABLE = '{{%variant_manager_attribute_options}}';

	public function safeUp(): bool
	{
		$this->createTable(Table::STRUCTURES, [
			'id' => $this->integer()->notNull(),
			'uid' => $this->uid(),
			'PRIMARY KEY([[id]])',
		]);

		$this->addForeignKey(null, Table::STRUCTURES, ['id'], CraftTable::STRUCTURES, ['id'], 'CASCADE');

		$structure = new Structure([
			'maxLevels' => 2,
		]);

		Craft::$app->getStructures()->saveStructure($structure);

		$this->insert(Table::STRUCTURES, [
			'id' => $structure->id,
		]);

		$this->addColumn(Table::ATTRIBUTES, 'attributeId', $this->integer()->notNull()->defaultValue(0)->after('id'));
		$this->dropIndexIfExists(Table::ATTRIBUTES, ['nameKey'], true);
		$this->createIndex(null, Table::ATTRIBUTES, ['attributeId', 'nameKey'], true);

		$options = (new Query())
			->from(self::OPTIONS_TABLE)
			->all();

		foreach ($options as $option) {
			$this->insert(Table::ATTRIBUTES, [
				'id' => $option['id'],
				'attributeId' => $option['attributeId'],
				'name' => $option['value'],
				'nameKey' => $option['valueKey'],
				'displayType' => 'dropdown',
				'dateCreated' => $option['dateCreated'],
				'dateUpdated' => $option['dateUpdated'],
				'uid' => $option['uid'],
			]);
		}

		if ($options !== []) {
			$this->update(CraftTable::ELEMENTS, [
				'type' => VariantAttribute::class,
			], [
				'id' => array_column($options, 'id'),
			], updateTimestamp: false);
		}

		$this->dropTableIfExists(self::OPTIONS_TABLE);

		$this->placeInStructure($structure->id);

		return true;
	}

	private function placeInStructure(int $structureId): void
	{
		$structuresService = Craft::$app->getStructures();

		$attributes = VariantAttribute::find()
			->attributeId(0)
			->trashed(null)
			->withStructure(false)
			->orderBy([
				'variant_manager_attributes.name' => SORT_ASC,
			])
			->all();

		foreach ($attributes as $attribute) {
			$structuresService->appendToRoot($structureId, $attribute);

			$attributeOptions = VariantAttribute::find()
				->attributeId($attribute->id)
				->trashed(null)
				->withStructure(false)
				->orderBy([
					'variant_manager_attributes.name' => SORT_ASC,
				])
				->all();

			foreach ($attributeOptions as $option) {
				$structuresService->append($structureId, $option, $attribute);
			}
		}
	}
}
