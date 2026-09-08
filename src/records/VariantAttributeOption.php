<?php

namespace fostercommerce\variantmanager\records;

use craft\db\ActiveRecord;
use fostercommerce\variantmanager\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $attributeId
 * @property string $value
 * @property string $valueKey
 */
class VariantAttributeOption extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::ATTRIBUTE_OPTIONS;
	}

	public function getVariantAttribute(): ActiveQueryInterface
	{
		return $this->hasOne(VariantAttribute::class, [
			'id' => 'attributeId',
		]);
	}
}
