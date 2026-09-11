<?php

namespace fostercommerce\variantmanager\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use fostercommerce\variantmanager\db\Table;

class m260905_153830_create_variant_attribute_tables extends Migration
{
	public function safeUp(): bool
	{
		$this->createTable(Table::ATTRIBUTES, [
			'id' => $this->integer()->notNull(),
			'name' => $this->string()->notNull(),
			'nameKey' => $this->string()->notNull(),
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
}
