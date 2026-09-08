<?php

namespace fostercommerce\variantmanager\records;

use craft\db\ActiveRecord;
use fostercommerce\variantmanager\db\Table;

/**
 * @property int $id
 * @property string $name
 * @property string $nameKey
 * @property string $displayType
 */
class VariantAttribute extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::ATTRIBUTES;
	}
}
