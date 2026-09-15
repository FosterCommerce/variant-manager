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
		// Skip what an earlier run applied, since MySQL keeps schema changes from a failed run
		$structureId = $this->structureId();

		$this->addAttributeIdColumn();

		if (Craft::$app->getDb()->tableExists(self::OPTIONS_TABLE, true)) {
			$this->copyOptions();
		}

		$this->placeInStructure($structureId);

		return true;
	}

	private function structureId(): int
	{
		if (Craft::$app->getDb()->tableExists(Table::STRUCTURES, true)) {
			return (int) (new Query())
				->select(['id'])
				->from(Table::STRUCTURES)
				->scalar();
		}

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

		return $structure->id;
	}

	private function addAttributeIdColumn(): void
	{
		if (Craft::$app->getDb()->columnExists(Table::ATTRIBUTES, 'attributeId', true)) {
			return;
		}

		$this->addColumn(Table::ATTRIBUTES, 'attributeId', $this->integer()->notNull()->defaultValue(0)->after('id'));
		$this->dropIndexIfExists(Table::ATTRIBUTES, ['nameKey'], true);
		$this->createIndex(null, Table::ATTRIBUTES, ['attributeId', 'nameKey'], true);
	}

	private function copyOptions(): void
	{
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
	}

	private function placeInStructure(int $structureId): void
	{
		$structuresService = Craft::$app->getStructures();

		// Read the rows directly, since a VariantAttribute query selects columns a later migration adds
		$rows = (new Query())
			->select([
				'attributes.id',
				'attributes.attributeId',
			])
			->from([
				'attributes' => Table::ATTRIBUTES,
			])
			->innerJoin([
				'elements' => CraftTable::ELEMENTS,
			], '[[elements.id]] = [[attributes.id]]')
			->where([
				'elements.draftId' => null,
				'elements.revisionId' => null,
				'elements.archived' => false,
			])
			->orderBy([
				'attributes.name' => SORT_ASC,
			])
			->all();

		$attributeIds = [];
		$optionIdsByAttributeId = [];

		foreach ($rows as $row) {
			$id = (int) $row['id'];
			$attributeId = (int) $row['attributeId'];

			if ($attributeId === 0) {
				$attributeIds[] = $id;
			} else {
				$optionIdsByAttributeId[$attributeId][] = $id;
			}
		}

		foreach ($attributeIds as $attributeId) {
			$attribute = new VariantAttribute([
				'id' => $attributeId,
			]);

			$structuresService->appendToRoot($structureId, $attribute);

			foreach ($optionIdsByAttributeId[$attributeId] ?? [] as $optionId) {
				$option = new VariantAttribute([
					'id' => $optionId,
				]);

				$structuresService->append($structureId, $option, $attribute);
			}
		}
	}
}
