<?php

namespace fostercommerce\variantmanager\elements;

use Craft;
use craft\commerce\elements\db\VariantQuery;
use craft\commerce\elements\Variant as CommerceVariant;
use craft\elements\conditions\ElementConditionInterface;
use fostercommerce\variantmanager\elements\db\VariantManagerVariantQuery;
use fostercommerce\variantmanager\elements\variants\VariantCondition;
use fostercommerce\variantmanager\Plugin;

class VariantManagerVariant extends CommerceVariant
{
	public static function find(): VariantQuery
	{
		return new VariantManagerVariantQuery(static::class);
	}

	/**
	 * @return VariantCondition
	 */
	public static function createCondition(): ElementConditionInterface
	{
		return Craft::createObject(VariantCondition::class, [static::class]);
	}

	protected static function defineFieldLayouts(?string $source): array
	{
		return Craft::$app->getFields()->getLayoutsByType(CommerceVariant::class);
	}

	protected static function defineSortOptions(): array
	{
		return [
			...parent::defineSortOptions(),
			// Keep translations from Commerce for now
			'price' => Craft::t('commerce', 'Price'),
			'promotionalPrice' => Craft::t('commerce', 'Promotional Price'),
			'stock' => Craft::t('commerce', 'Stock'),
			'minQty' => Craft::t('commerce', 'Min Qty'),
			'maxQty' => Craft::t('commerce', 'Max Qty'),
			'availableForPurchase' => Craft::t('commerce', 'Available for purchase'),
			'inventoryTracked' => Craft::t('commerce', 'Inventory Tracked'),
		];
	}

	protected static function defineDefaultTableAttributes(string $source): array
	{
		return [
			'product',
			...parent::defineDefaultTableAttributes($source),
			'inventoryTracked',
			'stock',
			...Plugin::getInstance()->getSettings()->defaultVariantTableAttributes,
		];
	}
}
