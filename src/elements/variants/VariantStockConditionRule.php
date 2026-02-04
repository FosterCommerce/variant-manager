<?php

namespace fostercommerce\variantmanager\elements\variants;

use Craft;
use craft\base\conditions\BaseNumberConditionRule;
use craft\base\ElementInterface;
use craft\commerce\elements\db\VariantQuery;
use craft\commerce\elements\Variant;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class VariantStockConditionRule extends BaseNumberConditionRule implements ElementConditionRuleInterface
{
	public function getLabel(): string
	{
		return Craft::t('variant-manager', 'Stock');
	}

	public function getExclusiveQueryParams(): array
	{
		return ['variantStock'];
	}

	/**
	 * @param VariantQuery $query
	 */
	public function modifyQuery(ElementQueryInterface $query): void
	{
		$query->stock($this->paramValue());
	}

	/**
	 * @param Variant $element
	 */
	public function matchElement(ElementInterface $element): bool
	{
		return $this->matchValue($element->getStock());
	}
}
