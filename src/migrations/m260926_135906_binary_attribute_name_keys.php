<?php

namespace fostercommerce\variantmanager\migrations;

use craft\db\Migration;
use fostercommerce\variantmanager\db\Table;

/**
 * m260926_135906_binary_attribute_name_keys migration.
 */
class m260926_135906_binary_attribute_name_keys extends Migration
{
	public function safeUp(): bool
	{
		// Compare name keys by bytes on MySQL, because its default collation treats "crème" and "creme" as one key
		if ($this->db->getIsMysql()) {
			$this->alterColumn(Table::ATTRIBUTES, 'nameKey', $this->string()->notNull() . " COLLATE {$this->db->charset}_bin");
		}

		return true;
	}
}
