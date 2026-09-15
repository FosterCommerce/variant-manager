<?php

namespace fostercommerce\variantmanager\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table as CraftTable;
use craft\models\Structure;
use fostercommerce\variantmanager\db\Table;

class Install extends Migration
{
	public function safeUp(): bool
	{
		$this->createTable(Table::ACTIVITIES, [
			'id' => $this->primaryKey(),
			'message' => $this->text()->notNull(),
			'type' => $this->string()->notNull(),
			'userId' => $this->integer()->notNull(),
			'username' => $this->string()->notNull(),
			'dateCreated' => $this->dateTime()->notNull(),
		]);
		$this->createIndex(null, Table::ACTIVITIES, ['dateCreated'], false);

		$this->createTable(Table::ATTRIBUTES, [
			'id' => $this->integer()->notNull(),
			'attributeId' => $this->integer()->notNull()->defaultValue(0),
			'name' => $this->string()->notNull(),
			'nameKey' => $this->string()->notNull(),
			'displayType' => $this->string()->notNull()->defaultValue('dropdown'),
			'skuPartial' => $this->string(),
			'priceModifier' => $this->decimal(14, 2),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
			'PRIMARY KEY([[id]])',
		]);

		// The key is the attribute plus the name, so Blue under two attributes is two rows
		// An attribute uses 0 rather than null, since MySQL treats null attributeIds as distinct
		$this->createIndex(null, Table::ATTRIBUTES, ['attributeId', 'nameKey'], true);
		$this->addForeignKey(null, Table::ATTRIBUTES, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE');

		$this->createTable(Table::STRUCTURES, [
			'id' => $this->integer()->notNull(),
			'uid' => $this->uid(),
			'PRIMARY KEY([[id]])',
		]);

		$this->addForeignKey(null, Table::STRUCTURES, ['id'], CraftTable::STRUCTURES, ['id'], 'CASCADE');

		$this->createTable(Table::VARIANT_MAKER, [
			'id' => $this->primaryKey(),
			'productId' => $this->integer()->notNull(),
			'settings' => $this->text(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Table::VARIANT_MAKER, ['productId'], true);
		$this->addForeignKey(null, Table::VARIANT_MAKER, ['productId'], CraftTable::ELEMENTS, ['id'], 'CASCADE');

		$structure = new Structure([
			'maxLevels' => 2,
		]);

		Craft::$app->getStructures()->saveStructure($structure);

		$this->insert(Table::STRUCTURES, [
			'id' => $structure->id,
		]);

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(Table::VARIANT_MAKER);
		$this->dropTableIfExists(Table::STRUCTURES);
		$this->dropTableIfExists(Table::ATTRIBUTES);

		if ($this->db->tableExists(Table::ACTIVITIES)) {
			$this->dropIndexIfExists(Table::ACTIVITIES, ['dateCreated'], false);
			$this->dropTable(Table::ACTIVITIES);
		}

		return true;
	}
}
