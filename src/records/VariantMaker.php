<?php

namespace fostercommerce\variantmanager\records;

use craft\db\ActiveRecord;
use fostercommerce\variantmanager\db\Table;

/**
 * @property int $id
 * @property int $productId
 * @property null|string $settings
 */
class VariantMaker extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::VARIANT_MAKER;
	}
}
