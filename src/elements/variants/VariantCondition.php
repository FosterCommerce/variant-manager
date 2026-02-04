<?php

namespace fostercommerce\variantmanager\elements\variants;

use craft\commerce\elements\conditions\purchasables\SkuConditionRule;
use craft\commerce\elements\conditions\variants\ProductConditionRule;
use craft\elements\conditions\ElementCondition;
use fostercommerce\variantmanager\elements\VariantManagerVariant;

class VariantCondition extends ElementCondition
{
	public ?string $elementType = VariantManagerVariant::class;

	protected function selectableConditionRules(): array
	{
		return array_merge(parent::selectableConditionRules(), [
			ProductConditionRule::class,
			SkuConditionRule::class,
			VariantInventoryTrackedConditionRule::class,
			VariantStockConditionRule::class,
		]);
	}
}
