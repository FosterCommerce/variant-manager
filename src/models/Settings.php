<?php

namespace fostercommerce\variantmanager\models;

use Craft;
use craft\base\Model;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as CommercePlugin;
use fostercommerce\variantmanager\enums\DisplayType;

/**
 * Variant Manager settings
 *
 * @property-read array $availableProductTypes
 */
class Settings extends Model
{
	public const DEFAULT_CLEAR_ACTIVITY_LOGS_AFTER = '30 days';

	public const DEFAULT_PRODUCT_FIELD_MAP = [
		'title' => 'title',
		'slug' => 'slug',
		'status' => 'status',
	];

	public const DEFAULT_VARIANT_FIELD_MAP = [
		'title' => 'title',
		'sku' => 'sku',
		'inventoryTracked' => 'inventoryTracked',
		'basePrice' => 'basePrice',
		'height' => 'height',
		'width' => 'width',
		'length' => 'length',
		'weight' => 'weight',
	];

	public string $emptyAttributeValue = '';

	public string $attributePrefix = 'Attribute: ';

	public string $inventoryPrefix = 'Inventory';

	/**
	 * How long to keep activity logs: an int is days, a string is a relative time like '1 week'.
	 *
	 * Logs are cleared during garbage collection, or by `./craft variant-manager/activities/clear`.
	 *
	 * @see https://www.php.net/manual/en/datetime.formats.php#datetime.formats.relative
	 */
	public string|int|null|false $activityLogRetention = self::DEFAULT_CLEAR_ACTIVITY_LOGS_AFTER;

	/**
	 * @var list<string>
	 */
	public array $defaultVariantTableAttributes = [];

	/**
	 * @var list<string>
	 */
	public array $bulkEditableVariantFields = [];

	/**
	 * @var list<string>
	 */
	public array $availableDisplayTypes = [];

	/**
	 * @var list<string> handles of the product types whose products offer the Variant Maker
	 */
	public array $variantMakerProductTypes = [];

	public string $defaultDisplayType = DisplayType::Dropdown->value;

	/**
	 * @var array<string, array<string, string>>
	 */
	public array $productFieldMap = [
		'*' => self::DEFAULT_PRODUCT_FIELD_MAP,
	];

	/**
	 * @var array<string, array<string, string>>
	 */
	public array $variantFieldMap = [
		'*' => self::DEFAULT_VARIANT_FIELD_MAP,
	];

	/**
	 * @param array<string, mixed> $values
	 */
	public function setAttributes($values, $safeOnly = true): void
	{
		// The “All” checkbox posts '*' on its own, and an unchecked group posts ''
		if (isset($values['availableDisplayTypes'])) {
			$values['availableDisplayTypes'] = array_values(array_filter(
				(array) $values['availableDisplayTypes'],
				static fn (mixed $displayType): bool => $displayType !== ''
			));
		}

		if (isset($values['variantMakerProductTypes'])) {
			$values['variantMakerProductTypes'] = array_values(array_filter(
				(array) $values['variantMakerProductTypes'],
				static fn (mixed $productTypeHandle): bool => $productTypeHandle !== ''
			));
		}

		parent::setAttributes($values, $safeOnly);

		if (is_int($this->activityLogRetention)) {
			$this->activityLogRetention = "{$this->activityLogRetention} days";
		}

		// getProductTypeMapping() reads the catch-all key without a guard
		if (! array_key_exists('*', $this->variantFieldMap)) {
			$this->variantFieldMap['*'] = self::DEFAULT_VARIANT_FIELD_MAP;
		}

		// getProductFieldMapping() reads the catch-all key without a guard
		if (! array_key_exists('*', $this->productFieldMap)) {
			$this->productFieldMap['*'] = self::DEFAULT_PRODUCT_FIELD_MAP;
		}
	}

	/**
	 * @return list<DisplayType>
	 */
	public function getAvailableDisplayTypes(?string $currentDisplayType = null): array
	{
		$displayTypes = $this->availableDisplayTypes === [] || in_array('*', $this->availableDisplayTypes, true)
			? DisplayType::cases()
			: array_values(array_filter(array_map(DisplayType::tryFrom(...), $this->availableDisplayTypes)));

		// Keep a stored type the config no longer lists, or the select posts a different one on the next save
		$currentDisplayType = $currentDisplayType === null ? null : DisplayType::tryFrom($currentDisplayType);
		if ($currentDisplayType instanceof DisplayType && ! in_array($currentDisplayType, $displayTypes, true)) {
			$displayTypes[] = $currentDisplayType;
		}

		return $displayTypes;
	}

	public function getDefaultDisplayType(): DisplayType
	{
		return DisplayType::tryFrom($this->defaultDisplayType) ?? DisplayType::Dropdown;
	}

	public function offersVariantMaker(string $productTypeHandle): bool
	{
		return in_array($productTypeHandle, $this->variantMakerProductTypes, true);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	public function getAvailableProductTypes(): array
	{
		$productTypes = [];
		/** @var CommercePlugin $plugin */
		$plugin = Craft::$app->plugins->getPlugin('commerce');
		foreach (array_keys($this->variantFieldMap) as $productTypeHandle) {
			if ($productTypeHandle === '*') {
				continue;
			}

			$productType = $plugin->productTypes->getProductTypeByHandle($productTypeHandle);
			if ($productType instanceof ProductType) {
				$productTypes[] = $productType;
			}
		}

		return $productTypes;
	}

	/**
	 * @return array<string, string>
	 */
	public function getProductTypeMapping(?string $productTypeHandle): array
	{
		return $productTypeHandle === null
			? $this->variantFieldMap['*']
			: $this->variantFieldMap[$productTypeHandle] ?? $this->variantFieldMap['*'];
	}

	/**
	 * @return array<string, string>
	 */
	public function getProductFieldMapping(?string $productTypeHandle): array
	{
		return $productTypeHandle === null
			? $this->productFieldMap['*']
			: $this->productFieldMap[$productTypeHandle] ?? $this->productFieldMap['*'];
	}
}
