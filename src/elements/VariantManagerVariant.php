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
	/**
	 * @return VariantQuery<int, self>
	 */
	public static function find(): VariantQuery
	{
		/** @var VariantQuery<int, self> $query */
		$query = new VariantManagerVariantQuery(static::class);

		return $query;
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

	/**
	 * @return array<string, mixed>
	 */
	protected static function defineSortOptions(): array
	{
		return [
			...parent::defineSortOptions(),
			// Sort options are Commerce attributes, so their labels translate in Commerce
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
