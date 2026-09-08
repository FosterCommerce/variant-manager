<?php

namespace fostercommerce\variantmanager\helpers;

use craft\base\ElementInterface;
use craft\models\FieldLayout;
use fostercommerce\variantmanager\fields\VariantAttributesField;

class FieldHelper
{
	public static function getFirstVariantAttributesField(?FieldLayout $fieldLayout): ?VariantAttributesField
	{
		if ($fieldLayout === null) {
			return null;
		}

		foreach ($fieldLayout->getCustomFields() as $field) {
			if ($field::class === VariantAttributesField::class) {
				return $field;
			}
		}

		return null;
	}

	public static function isFirstVariantAttributesField(VariantAttributesField $variantAttributesField, ?ElementInterface $element): bool
	{
		// No element means nothing to rank, as when the input renders as a field preview
		if ($element === null) {
			return true;
		}

		$customFieldIndex = -1;
		foreach ($element->getFieldLayout()->getCustomFields() as $customField) {
			if ($customField::class === VariantAttributesField::class) {
				++$customFieldIndex;

				if ($customField->id === $variantAttributesField->id) {
					return $customFieldIndex === 0;
				}
			}
		}

		return false;
	}
}
