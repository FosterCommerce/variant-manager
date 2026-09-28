<?php

namespace fostercommerce\variantmanager\models;

use Craft;
use craft\base\Model;
use craft\helpers\Json;
use craft\helpers\Localization;
use fostercommerce\variantmanager\services\VariantMaker;

class VariantMakerSettings extends Model
{
	public const PROPERTY_TITLE = 'title';

	public const PROPERTY_SKU = 'sku';

	public const PROPERTY_PRICE = 'price';

	public const PROPERTY_INVENTORY_TRACKED = 'inventoryTracked';

	public const PROPERTY_STOCK = 'stock';

	public const PROPERTY_ALLOW_OUT_OF_STOCK_PURCHASES = 'allowOutOfStockPurchases';

	public const PROPERTY_AVAILABLE_FOR_PURCHASE = 'availableForPurchase';

	public const PROPERTY_FREE_SHIPPING = 'freeShipping';

	public const PROPERTY_PROMOTABLE = 'promotable';

	/**
	 * @var list<array{attributeId: int, optionIds: list<int>}> in the order their SKU partials assemble
	 */
	public array $rows = [];

	/**
	 * @phpstan-var VariantMaker::MODE_*
	 */
	public string $mode = VariantMaker::MODE_ADD;

	/**
	 * @var array<string, VariantMakerProperty> keyed by the purchasable property the maker manages
	 */
	public array $properties = [];

	public ?int $inventoryLocationId = null;

	/**
	 * @return list<string>
	 */
	public static function propertyNames(): array
	{
		return [
			self::PROPERTY_TITLE,
			self::PROPERTY_SKU,
			self::PROPERTY_PRICE,
			self::PROPERTY_INVENTORY_TRACKED,
			self::PROPERTY_STOCK,
			self::PROPERTY_ALLOW_OUT_OF_STOCK_PURCHASES,
			self::PROPERTY_AVAILABLE_FOR_PURCHASE,
			self::PROPERTY_FREE_SHIPPING,
			self::PROPERTY_PROMOTABLE,
		];
	}

	public static function fromJson(?string $json): self
	{
		$stored = Json::decodeIfJson($json ?? '');
		$stored = is_array($stored) ? $stored : [];

		// Keep only the keys this model still declares. An earlier shape stored others.
		$settings = new self(array_intersect_key($stored, array_flip((new self())->attributes())));
		$settings->properties = self::propertiesFromPost(array_map(
			static fn (mixed $property): array => (array) $property,
			$settings->properties,
		));

		// Parse a price 4.2.2 stored as typed, such as "12,50"
		$price = $settings->properties[self::PROPERTY_PRICE];

		if (is_string($price->value) && ! self::isPlainDecimal($price->value)) {
			/** @var string $normalizedPrice */
			$normalizedPrice = Localization::normalizeNumber($price->value);
			$price->value = $normalizedPrice;
		}

		return $settings;
	}

	public function updatesExisting(): bool
	{
		return $this->mode === VariantMaker::MODE_UPDATE || $this->mode === VariantMaker::MODE_REPLACE;
	}

	/**
	 * Stock and out of stock purchases apply only while the maker tracks inventory, and a blank stock count doesn't write stock.
	 */
	public function manages(string $propertyName): bool
	{
		if (! $this->property($propertyName)->include) {
			return false;
		}

		if (! in_array($propertyName, [self::PROPERTY_STOCK, self::PROPERTY_ALLOW_OUT_OF_STOCK_PURCHASES], true)) {
			return true;
		}

		if ($propertyName === self::PROPERTY_STOCK && $this->property($propertyName)->value === null) {
			return false;
		}

		$tracked = $this->property(self::PROPERTY_INVENTORY_TRACKED);

		return $tracked->include && $tracked->value === true;
	}

	public function property(string $propertyName): VariantMakerProperty
	{
		return $this->properties[$propertyName] ?? new VariantMakerProperty();
	}

	/**
	 * Builds settings from what the product form posted.
	 *
	 * @param array<mixed> $postedSettings
	 */
	public static function fromPost(array $postedSettings): self
	{
		$rows = [];
		$postedRows = $postedSettings['rows'] ?? [];

		foreach (is_array($postedRows) ? $postedRows : [] as $postedRow) {
			$postedRow = (array) $postedRow;
			$optionIds = array_values(array_filter(array_map('intval', (array) ($postedRow['optionIds'] ?? []))));

			$rows[] = [
				'attributeId' => (int) ($postedRow['attributeId'] ?? 0),
				'optionIds' => $optionIds,
			];
		}

		$properties = self::propertiesFromPost((array) ($postedSettings['properties'] ?? []));
		$price = $properties[self::PROPERTY_PRICE];

		// Parse the price in the merchant's locale, because the queue job runs in another
		if (is_string($price->value)) {
			/** @var string $normalizedPrice */
			$normalizedPrice = Localization::normalizeNumber(trim($price->value));
			$price->value = $normalizedPrice;
		}

		return new self([
			'rows' => $rows,
			'mode' => (string) ($postedSettings['mode'] ?? VariantMaker::MODE_ADD),
			'properties' => $properties,
			'inventoryLocationId' => ($postedSettings['inventoryLocationId'] ?? '') === '' ? null : (int) $postedSettings['inventoryLocationId'],
		]);
	}

	/**
	 * Drops rows and options that were deleted or moved to another attribute, since the registry is what variants match on.
	 *
	 * @param list<int> $knownIds
	 * @param array<int, int> $attributeIdsByOptionId each known option's attribute, so an option moved away from a row's attribute is dropped
	 */
	public function forgetMissing(array $knownIds, array $attributeIdsByOptionId = []): void
	{
		$known = array_flip($knownIds);
		$rows = [];

		foreach ($this->rows as $row) {
			if (! isset($known[$row['attributeId']])) {
				continue;
			}

			// Drop an option that moved to another attribute, because the row builds pairs under its own attribute
			$optionIds = array_values(array_filter($row['optionIds'], static fn (int $optionId): bool => isset($known[$optionId])
				&& ($attributeIdsByOptionId[$optionId] ?? $row['attributeId']) === $row['attributeId']));

			// Drop a row whose options were all deleted or moved, because a row without options fails every product save
			if ($optionIds === [] && $row['optionIds'] !== []) {
				continue;
			}

			$rows[] = [
				'attributeId' => $row['attributeId'],
				'optionIds' => $optionIds,
			];
		}

		$this->rows = $rows;
	}

	public function toJson(): string
	{
		return Json::encode([
			'rows' => $this->rows,
			'mode' => $this->mode,
			'properties' => array_map(
				static fn (VariantMakerProperty $property): array => [
					'include' => $property->include,
					'value' => $property->value,
				],
				$this->properties,
			),
			'inventoryLocationId' => $this->inventoryLocationId,
		]);
	}

	public function validateRows(): void
	{
		$positionsByAttributeId = [];

		foreach ($this->rows as $rowIndex => $row) {
			$position = $rowIndex + 1;

			if ($row['attributeId'] === 0) {
				$this->addError('rows', Craft::t('variant-manager', 'variantMaker.rowNumberNeedsAttribute', [
					'row' => $position,
				]));
				continue;
			}

			// Refuse a repeated attribute, because the selection keeps one row per attribute and drops the other's options
			if (isset($positionsByAttributeId[$row['attributeId']])) {
				$this->addError('rows', Craft::t('variant-manager', 'variantMaker.rowNumberRepeatsAttribute', [
					'row' => $position,
					'first' => $positionsByAttributeId[$row['attributeId']],
				]));
				continue;
			}

			$positionsByAttributeId[$row['attributeId']] = $position;

			if ($row['optionIds'] === []) {
				$this->addError('rows', Craft::t('variant-manager', 'variantMaker.rowNumberNeedsOptions', [
					'row' => $position,
				]));
			}
		}
	}

	public function validatePrice(): void
	{
		$price = $this->property(self::PROPERTY_PRICE)->value;

		if ($price !== null && ! self::isPlainDecimal((string) $price)) {
			$this->addError('properties', Craft::t('variant-manager', 'variantMaker.priceNotNumber', [
				'price' => $price,
			]));
		}
	}

	/**
	 * Whether a price is the plain decimal Money's parser reads, which is stricter than is_numeric().
	 */
	public static function isPlainDecimal(string $price): bool
	{
		return preg_match('/^-?\d+(\.\d+)?$/', $price) === 1;
	}

	public static function isRequired(string $propertyName): bool
	{
		return in_array($propertyName, [self::PROPERTY_SKU, self::PROPERTY_PRICE], true);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		$rules = parent::defineRules();
		$rules[] = [['rows'], 'validateRows'];
		$rules[] = [['properties'], 'validatePrice'];
		$rules[] = [['mode'],
			'in',
			'range' => [VariantMaker::MODE_ADD, VariantMaker::MODE_UPDATE, VariantMaker::MODE_REPLACE]];
		return $rules;
	}

	/**
	 * A property's value is a format for the text ones, a count for stock, and a flag for the rest.
	 */
	private static function propertyValue(string $propertyName, mixed $postedValue): bool|int|string|null
	{
		if (in_array($propertyName, [self::PROPERTY_TITLE, self::PROPERTY_SKU, self::PROPERTY_PRICE], true)) {
			return is_scalar($postedValue) && (string) $postedValue !== '' ? (string) $postedValue : null;
		}

		if ($propertyName === self::PROPERTY_STOCK) {
			return is_scalar($postedValue) && (string) $postedValue !== '' ? (int) $postedValue : null;
		}

		return (bool) $postedValue;
	}

	/**
	 * @param array<mixed> $postedProperties
	 * @return array<string, VariantMakerProperty>
	 */
	private static function propertiesFromPost(array $postedProperties): array
	{
		$properties = [];

		foreach (self::propertyNames() as $propertyName) {
			$postedProperty = (array) ($postedProperties[$propertyName] ?? []);

			$properties[$propertyName] = new VariantMakerProperty([
				// A disabled switch posts nothing, and Commerce requires these two on every variant anyway
				'include' => self::isRequired($propertyName) || (bool) ($postedProperty['include'] ?? false),
				'value' => self::propertyValue($propertyName, $postedProperty['value'] ?? null),
			]);
		}

		return $properties;
	}
}
