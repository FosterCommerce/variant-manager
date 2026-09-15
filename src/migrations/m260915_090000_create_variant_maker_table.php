<?php

namespace fostercommerce\variantmanager\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use fostercommerce\variantmanager\db\Table;

class m260915_090000_create_variant_maker_table extends Migration
{
	public function safeUp(): bool
	{
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

		return true;
	}
}
