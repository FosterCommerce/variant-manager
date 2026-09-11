<?php

namespace fostercommerce\variantmanager\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
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
			'name' => $this->string()->notNull(),
			'nameKey' => $this->string()->notNull(),
			'displayType' => $this->string()->notNull()->defaultValue('dropdown'),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
			'PRIMARY KEY([[id]])',
		]);

		$this->createIndex(null, Table::ATTRIBUTES, ['nameKey'], true);
		$this->addForeignKey(null, Table::ATTRIBUTES, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE');

		$this->createTable(Table::ATTRIBUTE_OPTIONS, [
			'id' => $this->integer()->notNull(),
			'attributeId' => $this->integer()->notNull(),
			'value' => $this->string()->notNull(),
			'valueKey' => $this->string()->notNull(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
			'PRIMARY KEY([[id]])',
		]);

		$this->createIndex(null, Table::ATTRIBUTE_OPTIONS, ['attributeId', 'valueKey'], true);
		$this->addForeignKey(null, Table::ATTRIBUTE_OPTIONS, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::ATTRIBUTE_OPTIONS, ['attributeId'], Table::ATTRIBUTES, ['id'], 'CASCADE');

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(Table::ATTRIBUTE_OPTIONS);
		$this->dropTableIfExists(Table::ATTRIBUTES);

		if ($this->db->tableExists(Table::ACTIVITIES)) {
			$this->dropIndexIfExists(Table::ACTIVITIES, ['dateCreated'], false);
			$this->dropTable(Table::ACTIVITIES);
		}

		return true;
	}
}
