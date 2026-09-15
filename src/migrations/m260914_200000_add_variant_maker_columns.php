<?php

namespace fostercommerce\variantmanager\migrations;

use craft\db\Migration;
use fostercommerce\variantmanager\db\Table;

class m260914_200000_add_variant_maker_columns extends Migration
{
	public function safeUp(): bool
	{
		$this->addColumn(Table::ATTRIBUTES, 'skuPartial', $this->string()->after('displayType'));
		$this->addColumn(Table::ATTRIBUTES, 'priceModifier', $this->decimal(14, 2)->after('skuPartial'));

		return true;
	}
}
