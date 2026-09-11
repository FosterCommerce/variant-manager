<?php

namespace fostercommerce\variantmanager\elements\variants;

use Craft;
use craft\commerce\elements\conditions\purchasables\SkuConditionRule;
use craft\commerce\elements\conditions\variants\ProductConditionRule;
use craft\commerce\elements\Variant as CommerceVariant;
use craft\elements\conditions\ElementCondition;
use fostercommerce\variantmanager\elements\VariantManagerVariant;

class VariantCondition extends ElementCondition
{
	public ?string $elementType = VariantManagerVariant::class;

	/**
	 * Resolve against the base Variant class, since layouts are stored under it.
	 */
	public function getFieldLayouts(): array
	{
		return Craft::$app->getFields()->getLayoutsByType(CommerceVariant::class);
	}

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
