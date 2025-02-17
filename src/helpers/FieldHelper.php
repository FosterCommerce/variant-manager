<?php

namespace fostercommerce\variantmanager\helpers;

use craft\base\ElementInterface;
use craft\base\Field;
use craft\models\FieldLayout;
use fostercommerce\variantmanager\fields\VariantAttributesField;

class FieldHelper extends Field
{
	public static function getFirstVariantAttributesField(FieldLayout $fieldLayout = null): ?VariantAttributesField
	{
		// Using getElementsByType(VariantAttributesField::class) gave inconsistent results. Maybe I was doing something wrong.
		foreach ($fieldLayout->getCustomFields() as $field) {
			if ($field::class === VariantAttributesField::class) {
				return $field;
			}
		}

		return null;
	}

	public static function isFirstVariantAttributesField(VariantAttributesField $variantAttributesField, ElementInterface $element = null): bool
	{
		// Using getElementsByType(VariantAttributesField::class) gave inconsistent results. Maybe I was doing something wrong.
		$customFieldIndex = -1;
		foreach ($element->getFieldLayout()->getCustomFields() as $customField) {
			if ($customField::class === VariantAttributesField::class) {
				++$customFieldIndex;

				if ($customField->id === $variantAttributesField->id) {
					return $customFieldIndex === 0;
				}
			}
		}

		// Shouldn't reach here ever. But if we do, then it's going to be false.
		return false;
	}
}
