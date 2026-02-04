<?php

namespace fostercommerce\variantmanager\elements\variants;

use Craft;
use craft\base\conditions\BaseLightswitchConditionRule;
use craft\base\ElementInterface;
use craft\commerce\elements\db\VariantQuery;
use craft\commerce\elements\Variant;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class VariantInventoryTrackedConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
	public function getLabel(): string
	{
		return Craft::t('variant-manager', 'Inventory Tracked');
	}

	public function getExclusiveQueryParams(): array
	{
		return ['inventoryTracked'];
	}

	/**
	 * @param VariantQuery $query
	 */
	public function modifyQuery(ElementQueryInterface $query): void
	{
		$query->inventoryTracked($this->value);
	}

	/**
	 * @param Variant $element
	 */
	public function matchElement(ElementInterface $element): bool
	{
		return $this->matchValue($element->inventoryTracked);
	}
}
