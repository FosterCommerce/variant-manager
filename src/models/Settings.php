<?php

namespace fostercommerce\variantmanager\models;

use Craft;
use craft\base\Model;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as CommercePlugin;

/**
 * Variant Manager settings
 *
 * @property-read array $availableProductTypes
 */
class Settings extends Model
{
	public const DEFAULT_CLEAR_ACTIVITY_LOGS_AFTER = '30 days';

	/**
	 * The value to use for empty attribute values.
	 */
	public string $emptyAttributeValue = '';

	/**
	 * The prefix to use for attribute fields.
	 */
	public string $attributePrefix = 'Attribute: ';

	/**
	 * The prefix to use for inventory fields.
	 */
	public string $inventoryPrefix = 'Inventory';

	/**
	 * If set, how long to keep individual activity logs for.
	 *
	 * For integer values, it will be number of days.
	 * For string values, it refers to a relative time string like '1 hour', '1 day', '1 week', '1 month', '1 year'.
	 *
	 * Note that activity logs are only cleared during Craft's garbage collection or when the `clear-activity-logs` console command is run.
	 *
	 * @see https://www.php.net/manual/en/datetime.formats.php#datetime.formats.relative
	 */
	public string|int|null|false $activityLogRetention = self::DEFAULT_CLEAR_ACTIVITY_LOGS_AFTER;

	public array $productFieldMap = [
		'*' => [],
	];

	public array $variantFieldMap = [
		'*' => [],
	];

	public function setAttributes($values, $safeOnly = true): void
	{
		parent::setAttributes($values, $safeOnly);

		if ($this->activityLogRetention !== false && $this->activityLogRetention !== null) {
			if (is_int($this->activityLogRetention)) {
				$this->activityLogRetention = "{$this->activityLogRetention} days";
			}
		}

		// Make sure that the catch-all type always exists
		if ($this->variantFieldMap === []) {
			$this->variantFieldMap = [
				'*' => [],
			];
		}

		if (! array_key_exists('*', $this->variantFieldMap)) {
			$this->variantFieldMap['*'] = [];
		}

		// Make sure that the catch-all type always exists for product field map
		if ($this->productFieldMap === []) {
			$this->productFieldMap = [
				'*' => [],
			];
		}

		if (! array_key_exists('*', $this->productFieldMap)) {
			$this->productFieldMap['*'] = [];
		}
	}

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

	public function getProductTypeMapping(?string $productTypeHandle): ?array
	{
		if ($productTypeHandle === null) {
			return $this->variantFieldMap['*'];
		}

		return $this->variantFieldMap[$productTypeHandle] ?? $this->variantFieldMap['*'];
	}

	public function getProductFieldMapping(?string $productTypeHandle): ?array
	{
		if ($productTypeHandle === null) {
			return $this->productFieldMap['*'];
		}

		return $this->productFieldMap[$productTypeHandle] ?? $this->productFieldMap['*'];
	}
}
