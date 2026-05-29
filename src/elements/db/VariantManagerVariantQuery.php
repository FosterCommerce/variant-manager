<?php

namespace fostercommerce\variantmanager\elements\db;

use Craft;
use craft\commerce\elements\db\VariantQuery;
use craft\commerce\elements\Variant as CommerceVariant;

class VariantManagerVariantQuery extends VariantQuery
{
	public function getCacheTags(): array
	{
		$tags = parent::getCacheTags();

		$mirrored = array_map(
			fn (string $tag): string => str_replace($this->elementType, CommerceVariant::class, $tag),
			$tags
		);

		return array_values(array_unique([...$tags, ...$mirrored]));
	}

	protected function fieldLayouts(): array
	{
		return Craft::$app->getFields()->getLayoutsByType(CommerceVariant::class);
	}
}
