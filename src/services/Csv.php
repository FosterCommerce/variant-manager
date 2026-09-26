<?php

namespace fostercommerce\variantmanager\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\commerce\collections\UpdateInventoryLevelCollection;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\enums\InventoryUpdateQuantityType;
use craft\commerce\models\inventory\UpdateInventoryLevel;
use craft\commerce\models\InventoryLocation;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as CommercePlugin;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\errors\ElementNotFoundException;
use craft\fields\Assets as AssetsField;
use craft\fields\BaseRelationField;
use craft\fields\Date as DateField;
use craft\fields\Entries;
use craft\fields\Lightswitch;
use craft\fields\Money as MoneyField;
use craft\helpers\DateTimeHelper;
use craft\helpers\ElementHelper;
use craft\helpers\Typecast;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Site;
use craft\models\Volume;
use DateTimeInterface;
use fostercommerce\variantmanager\errors\FieldMapException;
use fostercommerce\variantmanager\errors\ImportDataException;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\Plugin;
use Illuminate\Support\Collection;
use League\Csv\CannotInsertRecord;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;
use League\Csv\Statement;
use League\Csv\TabularDataReader;
use League\Csv\UnableToProcessCsv;
use League\Csv\Writer;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;

class Csv extends Component
{
	private const STANDARD_VARIANT_FIELDS = [
		'title',
		'enabled',
		'isDefault',
		'sku',
		'width',
		'height',
		'length',
		'weight',
	];

	private const STANDARD_PER_SITE_VARIANT_FIELDS = [
		'basePrice',
		'inventoryTracked',
		'availableForPurchase',
		'freeShipping',
		'promotable',
		'minQty',
		'maxQty',
	];

	/**
	 * @var list<string>
	 */
	private const NATIVE_PRODUCT_COLUMNS = ['title', 'slug', 'status'];

	/**
	 * @throws CsvException
	 * @throws ImportDataException
	 * @throws FieldMapException
	 * @throws Exception
	 * @throws InvalidConfigException
	 * @throws UnableToProcessCsv
	 * @throws ElementNotFoundException
	 * @throws Throwable
	 */
	public function import(string $filename, string $csvData, ?string $productTypeHandle, bool $refreshVariants = false): Product
	{
		$tabularDataReader = $this->read($csvData);
		$titleRecord = array_filter($tabularDataReader->nth(0), static fn ($value): bool => $value !== null);
		if ($titleRecord === []) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.invalidProductTitle'));
		}

		$productId = explode('__', $filename)[0] ?? null;
		if (! ctype_digit((string) $productId)) {
			$productId = null;
		}

		$product = $this->resolveProductModel($titleRecord[array_key_first($titleRecord)], $productId, $productTypeHandle);

		if ($productTypeHandle === null) {
			/** @var string $productTypeHandle */
			$productTypeHandle = $product->type->handle;
		}

		$mapping = $this->resolveVariantImportMapping($tabularDataReader, $productTypeHandle);

		$this->validateSkus($product, $mapping, $tabularDataReader);

		$this->applyProductFields($product, $titleRecord);

		$replacedVariants = [];

		try {
			if ($product->isNewForSite) {
				$variants = $this->normalizeNewProductImport($tabularDataReader, $mapping);
			} else {
				// $refreshVariants and normalizeExistingProductImport() both delete variants
				$replacedVariants = Variant::find()->product($product)->status(null)->all();

				if ($refreshVariants) {
					foreach ($replacedVariants as $replacedVariant) {
						Craft::$app->elements->deleteElement($replacedVariant);
					}
				}

				$variants = $this->normalizeExistingProductImport($product, $tabularDataReader, $mapping);
			}

			$this->saveVariants($product, $variants);

			// The import fails validation when the mapping has no SKU column
			/** @var int $skuColumn */
			$skuColumn = $mapping['variant']['sku'];
			$this->importSiteSpecificData($tabularDataReader, $skuColumn, $mapping['sites']);
			$this->importInventoryLevels($tabularDataReader, $skuColumn, $mapping['inventory']);
		} catch (Throwable $throwable) {
			if ($product->isNewForSite) {
				if ($product->id !== null) {
					Craft::$app->elements->deleteElement($product);
				}
			} else {
				$this->restoreReplacedVariants($product, $replacedVariants);
			}

			throw $throwable;
		}

		return $product;
	}

	/**
	 * Saves a product's variants in the order Commerce needs.
	 *
	 * @param list<Variant> $variants
	 * @throws Throwable
	 */
	public function saveVariants(Product $product, array $variants): void
	{
		// Save a new product first. Variants need its ID.
		if ($product->isNewForSite && ! Craft::$app->elements->saveElement($product, false, true, true)) {
			$errors = $product->getErrorSummary(false);
			throw new ImportDataException($errors[0] ?? Craft::t('variant-manager', 'import.productSaveFailed'));
		}

		$product->setVariants($variants);
		$product->setScenario(Element::SCENARIO_LIVE);

		// Save each variant after the product, because Commerce generates variant titles from the owner
		foreach ($variants as $variant) {
			$variant->setOwner($product);
			if (! Craft::$app->elements->saveElement($variant, false, true, true)) {
				$errors = $variant->getErrorSummary(false);
				throw new ImportDataException($errors[0] ?? Craft::t('variant-manager', 'import.variantSaveFailed'));
			}
		}

		// Validate, so an invalid product fails the import instead of saving half-formed
		if (! Craft::$app->elements->saveElement($product, true, true, true)) {
			$errors = $product->getErrorSummary(false);
			throw new ImportDataException(($errors[0] ?? Craft::t('variant-manager', 'import.productSaveFailed')) . $this->repeatedSkus($product));
		}
	}

	/**
	 * @return array{filename: string, export: string}|false
	 * @throws CannotInsertRecord
	 * @throws CsvException
	 * @throws FieldMapException
	 */
	public function export(string $productId): array|bool
	{
		// Export disabled products and variants too
		/** @var Product|null $product */
		$product = Product::find()->id($productId)->status(null)->one();

		if (! isset($product)) {
			return false;
		}

		return [
			'filename' => "{$product->id}__{$product->slug}",
			'export' => $this->exportProduct($product, Variant::find()->product($product)->status(null)->all()),
		];
	}

	/**
	 * @param list<Variant> $variants
	 * @throws CannotInsertRecord
	 * @throws CsvException
	 * @throws FieldMapException
	 */
	public function exportProduct(Product $product, array $variants): string
	{
		$sites = Craft::$app->sites->allSites;
		$mapping = $this->resolveVariantExportMapping($product, $variants, $sites);
		$productMapping = $this->resolveProductExportMapping($product);

		$writer = Writer::fromString();

		// Headers include variant fields and attributes
		$inventoryHeaders = [];
		foreach ($mapping['inventory'] as $headers) {
			$inventoryHeaders = [
				...$inventoryHeaders,
				...array_values($headers),
			];
		}

		$sitesHeaders = [];
		foreach ($mapping['sites'] as $headers) {
			$sitesHeaders = [
				...$sitesHeaders,
				...array_values($headers),
			];
		}

		$productHeaders = array_map(static fn ($fieldMap): string => $fieldMap[1], $productMapping);

		// Order:
		// 1. Product field mapping
		// 2. Variant field mapping (This may change if fields are set per site in the future)
		// 3. Commerce-specific variant fields which are different per site
		// 4. Inventory fields for each inventory location
		// 5. Variant Attribute fields
		$header = array_merge(
			$productHeaders,
			array_map(
				static fn ($fieldMap): string => $fieldMap[1],
				$mapping['variant']
			),
			$sitesHeaders,
			$inventoryHeaders,
			array_values($mapping['attribute'])
		);

		$dedupedHeader = array_values(array_unique($header));

		$writer->insertOne($dedupedHeader);

		// First row contains product title and product fields
		$productRow = $this->normalizeProductExport($product, $productMapping);
		$writer->insertOne($productRow);

		$productHeaderCount = count($productHeaders);
		$productCells = array_fill(0, $productHeaderCount, '');
		foreach ($variants as $variant) {
			$row = array_merge($productCells, $this->normalizeVariantExport($variant, $mapping, $sites));
			if (count($row) < count($header)) {
				$row = array_merge($row, array_fill(count($row), count($header) - count($row), ''));
			}

			// Collapse columns sharing a header, because a variant column would otherwise duplicate a product column
			$row = array_values(array_combine($header, $row));
			$writer->insertOne($row);
		}

		return $writer->toString();
	}

	/**
	 * @param string[] $items
	 * @return Collection<array-key, string[]>
	 * @throws InvalidConfigException
	 */
	protected function findProductVariantSkus(array $items): Collection
	{
		return collect(Variant::find()->sku($items)->status(null)->all())
			->groupBy(fn ($variant) => $variant->getOwner()->id)
			->map(static fn ($variants) => $variants->map(static fn ($variant) => $variant->sku)->all());
	}

	/**
	 * Restores a product's variants after an import fails partway.
	 *
	 * @param list<Variant> $replacedVariants
	 */
	private function restoreReplacedVariants(Product $product, array $replacedVariants): void
	{
		$elementsService = Craft::$app->elements;
		$replacedIds = array_map(static fn (Variant $replacedVariant): int => (int) $replacedVariant->id, $replacedVariants);

		// An imported variant may share a SKU with a replaced one
		foreach (Variant::find()->product($product)->status(null)->all() as $importedVariant) {
			if (! in_array((int) $importedVariant->id, $replacedIds, true)) {
				$elementsService->deleteElement($importedVariant, true);
			}
		}

		$elementsService->restoreElements(Variant::find()->id($replacedIds)->status(null)->trashed(true)->all());
	}

	/**
	 * Commerce reports a repeated SKU without naming it, leaving no way to tell which variants collided.
	 */
	private function repeatedSkus(Product $product): string
	{
		$skus = [];

		foreach ($product->getVariants(true) as $variant) {
			$skus[] = (string) $variant->sku;
		}

		$repeated = array_keys(array_filter(
			array_count_values($skus),
			static fn (int $count): bool => $count > 1,
		));

		return $repeated === [] ? '' : ' ' . Craft::t('variant-manager', 'import.repeatedSkus', [
			'skus' => implode(', ', $repeated),
		]);
	}

	/**
	 * @param TabularDataReader<array<string, string|null>> $reader
	 * @param int $skuColumn
	 * @param array<int, array{string, string}> $sitesMap
	 */
	private function importSiteSpecificData(TabularDataReader $reader, $skuColumn, array $sitesMap): void
	{
		$sites = [];
		foreach ($sitesMap as $key => $value) {
			$sites[] = [
				'field' => $value[0],
				'siteHandle' => $value[1],
				'index' => $key,
			];
		}

		$sites = Collection::make($sites)->groupBy('siteHandle');

		$iterator = $reader->getIterator();
		foreach ($iterator as $record) {
			if ($iterator->key() === 1) {
				// Skip the title record
				continue;
			}

			$record = array_values($record);

			if ($record === []) {
				continue;
			}

			foreach ($sites as $siteHandle => $data) {
				/** @var Variant|null $variant */
				$variant = Variant::find()->sku($record[$skuColumn])->site($siteHandle)->status(null)->one();
				if ($variant === null) {
					// The product is not propagated to this site, so the column has nowhere to write
					Craft::warning("Skipped per-site values for SKU {$record[$skuColumn]} on site {$siteHandle}", __METHOD__);
					continue;
				}

				if ($data->firstWhere('field', 'availableForPurchase') === null) {
					// If the availableForPurchase field does not exist, then we will default it to true
					$variant->availableForPurchase = true;
				}

				if ($data->firstWhere('field', 'promotable') === null) {
					// If the promotable field does not exist, then we will default it to true
					$variant->promotable = true;
				}

				foreach ($data as $fieldData) {
					$field = $fieldData['field'];
					$properties = [
						$field => $record[$fieldData['index']],
					];

					if ($field === 'availableForPurchase' || $field === 'promotable') {
						$value = $properties[$field];
						if ($value === null || $value === '') {
							// Default it to true if it's set but null or empty
							$properties[$field] = true;
						}
					}

					Typecast::properties(Variant::class, $properties);

					if ($field === 'basePrice') {
						$properties[$field] = (float) $properties[$field];
					}

					$variant->{$field} = reset($properties);
				}

				Craft::$app->elements->saveElement($variant);
			}
		}
	}

	/**
	 * @param TabularDataReader<array<string, string|null>> $reader
	 * @param int $skuColumn
	 * @param array<int, array{string, string}> $inventoryMap
	 * @throws InvalidConfigException
	 */
	private function importInventoryLevels(TabularDataReader $reader, $skuColumn, array $inventoryMap): void
	{
		$iterator = $reader->getIterator();
		foreach ($iterator as $record) {
			if ($iterator->key() === 1) {
				// Skip the title record
				continue;
			}

			$record = array_values($record);

			if ($record === []) {
				continue;
			}

			/** @var Variant|null $variant */
			$variant = Variant::find()->sku($record[$skuColumn])->status(null)->one();

			if ($variant === null) {
				// The row named a SKU the import did not save, so no variant holds the inventory
				Craft::warning("Skipped inventory for SKU {$record[$skuColumn]}", __METHOD__);
				continue;
			}

			if (! $variant->inventoryTracked) {
				continue;
			}

			$inventories = [];
			foreach ($inventoryMap as $index => $value) {
				$locationHandle = $value[0];
				$location = $inventories[$locationHandle] ?? [];
				$location[] = [
					$value[1] => $record[$index],
				];

				$inventories[$locationHandle] = $location;
			}

			$inventoryLevels = $variant->getInventoryLevels();
			$updates = [];
			foreach ($inventoryLevels as $inventoryLevel) {
				$inventoryItem = $inventoryLevel->getInventoryItem();
				$inventoryLocation = $inventoryLevel->getInventoryLocation();
				$totals = $inventories[$inventoryLocation->handle] ?? [];
				$note = 'Quantity set by the Variant Manager plugin';
				$updateAction = InventoryUpdateQuantityType::SET;

				foreach ($totals as $total) {
					$type = array_key_first($total);
					$quantity = reset($total);

					$updates[] = new UpdateInventoryLevel([
						'type' => $type,
						'updateAction' => $updateAction,
						'inventoryItem' => $inventoryItem,
						'inventoryLocation' => $inventoryLocation,
						'quantity' => $quantity,
						'note' => $note,
					]);
				}
			}

			/** @var CommercePlugin $commerce */
			$commerce = CommercePlugin::getInstance();
			$commerce->getInventory()->executeUpdateInventoryLevels(UpdateInventoryLevelCollection::make($updates));
		}
	}

	/**
	 * @param array{variant: array<string, int|null>} $mapping
	 * @param TabularDataReader<array<string, string|null>> $tabularDataReader
	 * @throws UnableToProcessCsv
	 */
	private function validateSkus(Product $product, array $mapping, TabularDataReader $tabularDataReader): void
	{
		$skuColumn = $mapping['variant']['sku'] ?? null;
		if ($skuColumn === null) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.missingSkuColumn'));
		}

		// Exit early if there are duplicate SKUs
		/** @var list<string> $skus */
		$skus = iterator_to_array($tabularDataReader->fetchColumn($skuColumn));

		$countedSkus = array_count_values($skus);
		$duplicateSkus = array_filter($countedSkus, static fn ($count): bool => $count > 1);
		if ($duplicateSkus !== []) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.duplicateSkus', [
				'skus' => implode(', ', array_keys($duplicateSkus)),
			]));
		}

		/** @var Collection<array-key, string[]> $foundSkus */
		$foundSkus = $this->findProductVariantSkus($skus);

		if ($product->isNewForSite && ! $foundSkus->isEmpty()) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.skusExist', [
				'skus' => implode(', ', $foundSkus->flatten()->values()->all()),
			]));
		}

		$foundSkus = $foundSkus->filter(static fn ($_value, $key): bool => $key !== $product->id);
		if (! $foundSkus->isEmpty()) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.skusExistElsewhere', [
				'skus' => implode(', ', $foundSkus->flatten()->values()->all()),
			]));
		}
	}

	/**
	 * @return TabularDataReader<array<string, string|null>>
	 * @throws CsvException
	 */
	private function read(string $csvData): TabularDataReader
	{
		$reader = Reader::fromString($csvData);
		$reader->setHeaderOffset(0);
		return (new Statement())->process($reader);
	}

	/**
	 * @param TabularDataReader<array<string, string|null>> $tabularDataReader
	 * @param array{variant: array<string, int|null>, attribute: array<int, array{int, string}>, fieldHandle: string|null, variantFieldLayout: FieldLayout} $mapping
	 * @return Variant[]
	 * @throws InvalidConfigException
	 */
	private function normalizeNewProductImport(TabularDataReader $tabularDataReader, array $mapping): array
	{
		$variants = [];
		$iterator = $tabularDataReader->getIterator();
		foreach ($iterator as $record) {
			if ($iterator->key() === 1) {
				// Skip the title record
				continue;
			}

			$record = array_values($record);

			if ($record === []) {
				continue;
			}

			$variants[] = $this->normalizeVariantImport($record, $mapping, 0);
		}

		return $variants;
	}

	/**
	 * @param TabularDataReader<array<string, string|null>> $tabularDataReader
	 * @param array{variant: array<string, int|null>, attribute: array<int, array{int, string}>, fieldHandle: string|null, variantFieldLayout: FieldLayout} $mapping
	 * @return Variant[]
	 * @throws InvalidConfigException
	 */
	private function normalizeExistingProductImport(Product $product, TabularDataReader $tabularDataReader, array $mapping): array
	{
		$iterator = $tabularDataReader->getIterator();
		$existingVariants = collect(Variant::find()->product($product)->status(null)->all());
		$newVariants = [];
		foreach ($iterator as $record) {
			if ($iterator->key() === 1) {
				// Skip the title record
				continue;
			}

			$record = array_values($record);

			if ($record === []) {
				continue;
			}

			// Cast both, because PHP compares two numeric strings numerically
			$sku = (string) $record[$mapping['variant']['sku']];
			$variant = $existingVariants->first(static fn (Variant $existingVariant): bool => (string) $existingVariant->sku === $sku)->id ?? 0;
			$newVariants[] = $this->normalizeVariantImport($record, $mapping, $variant);
		}

		// Match on id. Two variants can share a title.
		$importedVariantIds = collect($newVariants)->pluck('id')->filter()->all();
		$removedVariants = $existingVariants->reject(static fn (Variant $existingVariant): bool => in_array($existingVariant->id, $importedVariantIds, true));
		foreach ($removedVariants as $variant) {
			Craft::$app->elements->deleteElement($variant);
		}

		return $newVariants;
	}

	/**
	 * @param list<string|null> $variant
	 * @param array{variant: array<string, int|null>, attribute: array<int, array{int, string}>, fieldHandle: string|null, variantFieldLayout: FieldLayout} $mapping
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function normalizeVariantImport(array $variant, array $mapping, int $variantId): Variant
	{
		$emptyAttributeValue = Plugin::getInstance()->getSettings()->emptyAttributeValue;
		// Generate attributes for variant attributes field
		$attributes = [];
		foreach ($mapping['attribute'] as $field) {
			$value = trim((string) $variant[$field[0]]);
			if ($value === '') {
				$value = $emptyAttributeValue;
			}

			$attributes[] = [
				'attributeName' => $field[1],
				'attributeValue' => $value,
			];
		}

		$mapped = [];
		$fields = [];

		if (! empty($mapping['fieldHandle'])) {
			$fields[$mapping['fieldHandle']] = $attributes;
		}

		foreach ($mapping['variant'] as $fieldHandle => $index) {
			if ($index !== null) {
				if (in_array($fieldHandle, self::STANDARD_VARIANT_FIELDS, true)) {
					$mapped[$fieldHandle] = $variant[$index];
				} else {
					$fields[$fieldHandle] = $variant[$index];
				}
			}
		}

		if ($variantId !== 0) {
			/** @var Variant $variantElement */
			$variantElement = Variant::find()->id($variantId)->status(null)->one();
		} else {
			$variantElement = new Variant();
		}

		Typecast::properties(Variant::class, $mapped);
		foreach ($mapped as $fieldHandle => $value) {
			$variantElement->{$fieldHandle} = $value;
		}

		foreach ($fields as $fieldHandle => $value) {
			$this->setFieldValue($variantElement, $fieldHandle, $value, $mapping['variantFieldLayout']);
		}

		return $variantElement;
	}

	/**
	 * @param TabularDataReader<array<string, string|null>> $tabularDataReader
	 * @return array{
	 *     variant: array<string, int|null>,
	 *     attribute: array<int, array{int, string}>,
	 *     sites: array<int, array{string, string}>,
	 *     inventory: array<int, array{string, string}>,
	 *     fieldHandle: string|null,
	 *     variantFieldLayout: FieldLayout,
	 * }
	 * @throws FieldMapException
	 */
	private function resolveVariantImportMapping(TabularDataReader $tabularDataReader, string $productTypeHandle): array
	{
		$settings = Plugin::getInstance()->getSettings();
		$attributePrefix = $settings->attributePrefix;

		if ($attributePrefix === '') {
			throw new FieldMapException(Craft::t('variant-manager', 'settings.blankAttributePrefix'));
		}

		$inventoryPrefix = $settings->inventoryPrefix;
		$productTypeMap = $settings->getProductTypeMapping($productTypeHandle);
		if ($productTypeMap === []) {
			throw new FieldMapException(Craft::t('variant-manager', 'settings.emptyVariantFieldMap'));
		}

		/** @var CommercePlugin $commerce */
		$commerce = CommercePlugin::getInstance();
		$productType = $commerce->productTypes->getProductTypeByHandle($productTypeHandle);

		$crossSiteProductTypeMap = array_filter(
			$productTypeMap,
			static fn ($mapping): bool => ! in_array($mapping, self::STANDARD_PER_SITE_VARIANT_FIELDS, true),
		);
		$remainderSiteProductTypeFields = array_diff(self::STANDARD_VARIANT_FIELDS, array_values($crossSiteProductTypeMap));
		$crossSiteProductTypeMap = [
			...$crossSiteProductTypeMap,
			...array_combine($remainderSiteProductTypeFields, $remainderSiteProductTypeFields),
		];

		$variantSiteMap = array_filter(
			$productTypeMap,
			static fn ($mapping): bool => in_array($mapping, self::STANDARD_PER_SITE_VARIANT_FIELDS, true),
		);
		$remainderSiteVariantFields = array_diff(self::STANDARD_PER_SITE_VARIANT_FIELDS, array_values($variantSiteMap));
		$variantSiteMap = [
			...$variantSiteMap,
			...array_combine($remainderSiteVariantFields, $remainderSiteVariantFields),
		];

		if (! $productType instanceof ProductType) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.invalidProductTypeHandle'));
		}

		$variantFieldLayout = $productType->getVariantFieldLayout();
		$fieldHandle = FieldHelper::getFirstVariantAttributesField($variantFieldLayout)?->handle;

		$variantMap = array_fill_keys(array_values($productTypeMap), null);

		$attributeMap = [];
		$inventoryMap = [];
		$sitesMap = [];
		/** @var list<string> $csvHeader */
		$csvHeader = $tabularDataReader->getHeader();
		foreach ($csvHeader as $i => $heading) {
			$heading = trim($heading);
			$matchedCrossSiteFieldMap = array_filter($crossSiteProductTypeMap, static fn ($mapping): bool => strcasecmp($heading, $mapping) === 0, ARRAY_FILTER_USE_KEY);
			$matchedVariantFieldMap = array_filter($variantSiteMap, static fn ($mapping): bool => stripos($heading, $mapping) === 0, ARRAY_FILTER_USE_KEY);

			if ($matchedCrossSiteFieldMap !== []) {
				$variantMap[current($matchedCrossSiteFieldMap)] = $i;
			} elseif ($matchedVariantFieldMap !== []) {
				$key = array_key_first($matchedVariantFieldMap);
				$value = $matchedVariantFieldMap[$key];
				$pattern = '/' . preg_quote($key, '/') . '\[(.*?)\]$/i';
				if (preg_match($pattern, $heading, $matches) !== 1) {
					throw new ImportDataException(Craft::t('variant-manager', 'import.missingSiteHandle', [
						'heading' => $heading,
					]));
				}

				if (Craft::$app->getSites()->getSiteByHandle($matches[1]) === null) {
					throw new ImportDataException(Craft::t('variant-manager', 'import.unknownSiteHandle', [
						'heading' => $heading,
						'handle' => $matches[1],
					]));
				}

				$sitesMap[$i] = [$value, $matches[1]];
			} elseif (str_starts_with($heading, $inventoryPrefix)) {
				$pattern = '/' . preg_quote($inventoryPrefix, '/') . '\[(.*?)\]:\s(.*?)$/';
				if (preg_match($pattern, $heading, $matches) !== 1) {
					throw new ImportDataException(Craft::t('variant-manager', 'import.malformedInventoryColumn', [
						'heading' => $heading,
					]));
				}

				$locationHandle = $matches[1];
				$totalHandle = $matches[2];
				$inventoryMap[$i] = [$locationHandle, $totalHandle];
			} elseif (str_starts_with($heading, $attributePrefix)) {
				$attributeMap[] = [$i, explode($attributePrefix, $heading)[1]];
			}
		}

		return [
			'variant' => $variantMap,
			'attribute' => $attributeMap,
			'sites' => $sitesMap,
			'inventory' => $inventoryMap,
			'fieldHandle' => $fieldHandle,
			'variantFieldLayout' => $variantFieldLayout,
		];
	}

	/**
	 * @throws InvalidConfigException
	 */
	private function resolveProductModel(string $title, ?string $productId, ?string $productTypeHandle): Product
	{
		if ($productId !== null) {
			/** @var Product|null $product */
			$product = Product::find()->id($productId)->status(null)->one();
			if ($product === null) {
				throw new ImportDataException(Craft::t('variant-manager', 'import.invalidProductId'));
			}
		} else {
			$product = new Product();
			$product->isNewForSite = true;
			$product->slug = ElementHelper::generateSlug($title);

			/** @var CommercePlugin $plugin */
			$plugin = Craft::$app->plugins->getPlugin('commerce');
			/**
			 * @var string $productTypeHandle
			 * @var ProductType $productType
			 */
			$productType = $plugin->getProductTypes()->getProductTypeByHandle($productTypeHandle);
			$product->typeId = $productType->id;
		}

		$product->title = $title;

		return $product;
	}

	/**
	 * @param array<string, array<string, string>> $map
	 * @return array<string, array<string, mixed>>
	 */
	private function valueMapFromMapping($map, mixed $defaultValue = ''): array
	{
		return array_map(
			/**
			 * @param array<string, string> $inventory
			 * @return array<string, mixed>
			 */
			static function (array $inventory) use ($defaultValue): array {
				foreach (array_keys($inventory) as $key) {
					$inventory[$key] = $defaultValue;
				}

				return $inventory;
			},
			$map,
		);
	}

	/**
	 * Normalize a value to a better format for CSV.
	 */
	private function normalizeValue(mixed $value): mixed
	{
		if ($value instanceof EntryQuery) {
			$value = collect($value->all())
				->map(static function ($element): string {
					/** @var Section $section */
					$section = $element->section;
					return "{$section->handle}:{$element->slug}";
				})
				->join(',');
		} elseif ($value instanceof AssetQuery) {
			$value = collect($value->all())
				->map(static fn ($asset): string => "{$asset->volume->handle}:{$asset->path}")
				->join(',');
		} elseif ($value instanceof ElementQuery) {
			$value = collect($value->all())
				->map(static fn ($element): ?string => $element->slug)
				->join(',');
		} elseif ($value instanceof Money) {
			$formatter = new DecimalMoneyFormatter(new ISOCurrencies());
			$value = $formatter->format($value);
		} elseif ($value instanceof DateTimeInterface) {
			$value = $value->format(DateTimeInterface::ATOM);
		} elseif (is_bool($value)) {
			$value = $value ? '1' : '0';
		}

		return $value;
	}

	/**
	 * @param array{variant: list<array{string, string}>, attribute: array<array-key, string>, fieldHandle: string|null, inventory: array<string, array<string, string>>, sites: array<string, array<string, string>>} $mapping
	 * @param Site[] $sites
	 * @return list<mixed>
	 */
	private function normalizeVariantExport(Variant $variant, array $mapping, array $sites): array
	{
		$row = [];

		// Add variant fields
		foreach ($mapping['variant'] as [$fieldHandle, $header]) {
			if ($fieldHandle === 'stock' && $variant->inventoryTracked) {
				// Leave stock empty when inventory is tracked. The levels export separately.
				$row[] = '';
				continue;
			}

			$row[] = $this->normalizeValue($variant->{$fieldHandle});
		}

		// Map variant values per site
		$mappedSiteValues = $this->valueMapFromMapping($mapping['sites']);
		foreach ($sites as $site) {
			$siteVariant = Variant::find()->id($variant->id)->site($site)->status(null)->one();
			$siteMapping = $mappedSiteValues[$site->handle] ?? [];
			foreach ($siteMapping as $key => $value) {
				$siteMapping[$key] = $this->normalizeValue($siteVariant->{$key});
			}

			$row = [
				...$row,
				...array_values($siteMapping),
			];
		}

		// Map inventory values
		$inventoryMapping = $mapping['inventory'];
		$mappedInventoryValues = $this->valueMapFromMapping($inventoryMapping);

		if ($variant->inventoryTracked) {
			$levels = $variant->getInventoryLevels();
			foreach ($levels as $level) {
				$location = $level->getInventoryLocation()->handle;
				$levelMapping = $inventoryMapping[$location] ?? [];
				$mappedValues = $mappedInventoryValues[$location] ?? [];
				foreach (array_keys($levelMapping) as $totalKey) {
					$mappedValues[$totalKey] = $level->{$totalKey};
				}

				$mappedInventoryValues[$location] = $mappedValues;
			}
		}

		foreach ($mappedInventoryValues as $mappedInventoryValue) {
			$row = [
				...$row,
				...array_values($mappedInventoryValue),
			];
		}

		// Map Variant Attributes field values
		if ($mapping['fieldHandle']) {
			$handle = $mapping['fieldHandle'];
			// Place each value under its own name, because this variant might store them in another order or not at all
			$valuesByName = array_column($variant->{$handle} ?? [], 'attributeValue', 'attributeName');
			foreach (array_keys($mapping['attribute']) as $attributeName) {
				$row[] = $valuesByName[$attributeName] ?? '';
			}
		}

		return $row;
	}

	/**
	 * @param Variant[] $variants
	 * @param Site[] $sites
	 * @return array{
	 *     variant: list<array{string, string}>,
	 *     attribute: array<array-key, string>,
	 *     fieldHandle: string|null,
	 *     inventory: array<string, array<string, string>>,
	 *     sites: array<string, array<string, string>>,
	 * }
	 * @throws InvalidConfigException
	 * @throws FieldMapException
	 */
	private function resolveVariantExportMapping(Product $product, array $variants, array $sites): array
	{
		$settings = Plugin::getInstance()->getSettings();
		$attributePrefix = $settings->attributePrefix;

		if ($attributePrefix === '') {
			throw new FieldMapException(Craft::t('variant-manager', 'settings.blankAttributePrefix'));
		}

		$inventoryPrefix = $settings->inventoryPrefix;

		$productTypeMapping = $settings->getProductTypeMapping($product->type->handle);
		if ($productTypeMapping === []) {
			throw new FieldMapException(Craft::t('variant-manager', 'settings.emptyVariantFieldMap'));
		}

		$variantMap = [];
		$commerceVariantFieldMap = array_combine(self::STANDARD_PER_SITE_VARIANT_FIELDS, self::STANDARD_PER_SITE_VARIANT_FIELDS);

		foreach (array_keys($productTypeMapping) as $heading) {
			$fieldHandle = $productTypeMapping[$heading];
			if (array_key_exists($fieldHandle, $commerceVariantFieldMap)) {
				$commerceVariantFieldMap[$fieldHandle] = $heading;
				continue;
			}

			$variantMap[] = [$fieldHandle, $heading];
		}

		$fieldHandle = null;
		$attributeMap = [];
		$inventoryMap = [];
		$mappedSites = [];
		// Prefer a tracked variant. Only a tracked variant has inventory levels.
		/** @var Variant|null $variant */
		$variant = Variant::find()->product($product)->inventoryTracked()->status(null)->one()
			?? Variant::find()->product($product)->status(null)->one();
		if ($variant !== null) {
			$fieldHandle = FieldHelper::getFirstVariantAttributesField($variant->getFieldLayout())?->handle;
			if ($fieldHandle !== null) {
				// Collect every name the product uses, because its variants can store different attributes
				foreach ($variants as $exportedVariant) {
					foreach ($exportedVariant->{$fieldHandle} ?? [] as $attribute) {
						$attributeMap[$attribute['attributeName']] ??= $attributePrefix . $attribute['attributeName'];
					}
				}
			}

			/** @var CommercePlugin $commerce */
			$commerce = CommercePlugin::getInstance();
			/** @var Collection<array-key, InventoryLocation> $allInventoryLocations */
			$allInventoryLocations = $commerce->getInventoryLocations()->getAllInventoryLocations();
			/** @var Collection<array-key, string> $inventoryLocations */
			$inventoryLocations = $allInventoryLocations->map(static fn ($l): string => $l->handle);
			foreach ($inventoryLocations as $inventoryLocation) {
				$prefix = "{$inventoryPrefix}[{$inventoryLocation}]: ";
				$inventoryMap[$inventoryLocation] = [
					'reservedTotal' => "{$prefix}reserved",
					'damagedTotal' => "{$prefix}damaged",
					'safetyTotal' => "{$prefix}safety",
					'qualityControlTotal' => "{$prefix}qualityControl",
					'committedTotal' => "{$prefix}committed",
					'availableTotal' => "{$prefix}available",
				];
			}

			foreach ($sites as $site) {
				$handle = $site->handle;
				$mappedSites[$handle] = [
					'basePrice' => "{$commerceVariantFieldMap['basePrice']}[{$handle}]",
					'inventoryTracked' => "{$commerceVariantFieldMap['inventoryTracked']}[{$handle}]",
					'availableForPurchase' => "{$commerceVariantFieldMap['availableForPurchase']}[{$handle}]",
					'freeShipping' => "{$commerceVariantFieldMap['freeShipping']}[{$handle}]",
					'promotable' => "{$commerceVariantFieldMap['promotable']}[{$handle}]",
					'minQty' => "{$commerceVariantFieldMap['minQty']}[{$handle}]",
					'maxQty' => "{$commerceVariantFieldMap['maxQty']}[{$handle}]",
				];
			}
		}

		return [
			'variant' => $variantMap,
			'attribute' => $attributeMap,
			'fieldHandle' => $fieldHandle,
			'inventory' => $inventoryMap,
			'sites' => $mappedSites,
		];
	}

	/**
	 * @param array<string, string|null> $titleRecord
	 */
	private function applyProductFields(Product $product, array $titleRecord): void
	{
		if ($titleRecord === []) {
			return;
		}

		$settings = Plugin::getInstance()->getSettings();
		$productFieldMapping = $settings->getProductFieldMapping($product->type->handle);

		collect($titleRecord)
			->only(array_keys($productFieldMapping))
			->mapWithKeys(static fn (mixed $value, string $heading) => [
				$productFieldMapping[$heading] => $value,
			])
			->filter(static fn ($value, $fieldHandle): bool => $fieldHandle !== 'title')
			->each(function (mixed $value, string $fieldHandle) use ($product): void {
				if ($fieldHandle === 'slug') {
					$product->slug = $value;
					return;
				}

				if ($fieldHandle === 'status') {
					$normalized = is_string($value) ? strtolower(trim($value)) : '';
					$product->enabled = $normalized !== 'disabled';
					return;
				}

				$this->setFieldValue($product, $fieldHandle, $value, $product->getFieldLayout());
			});
	}

	private function setFieldValue(Element $element, string $fieldHandle, mixed $value, ?FieldLayout $fieldLayout): void
	{
		$field = $fieldLayout?->getFieldByHandle($fieldHandle);

		// Skip a handle this layout has no field for, since the save drops what CustomFieldBehavior accepted
		if (! $field instanceof FieldInterface) {
			return;
		}

		if ($field instanceof Entries) {
			/** @var list<string>|'*' $sectionSources */
			$sectionSources = $field->sources;
			$sectionUids = $sectionSources === '*'
				? []
				: array_map(static fn (string $source): string => str_replace('section:', '', $source), $sectionSources);
			$sectionHandles = array_map(static fn ($uid) => Craft::$app->entries->getSectionByUid($uid)?->handle, $sectionUids);

			// The CSV identifies entries as sectionHandle:slug pairs
			/** @var string|null $value */
			$slugs = collect(explode(',', (string) $value))->map(static fn ($slug): array => explode(':', $slug))->all();
			$entries = [];
			foreach ($slugs as $slug) {
				$sectionHandle = $slug[0];
				$slug = $slug[1] ?? null;

				if ($slug === null) {
					continue;
				}

				if ($sectionUids !== [] && ! in_array($sectionHandle, $sectionHandles, true)) {
					continue;
				}

				/** @var Entry|null $entry */
				$entry = Entry::find()->slug($slug)->section($sectionHandle)->one();
				if ($entry === null) {
					continue;
				}

				$entries[] = $entry->id;
			}

			$element->setFieldValue($fieldHandle, $entries);
		} elseif ($field instanceof MoneyField) {
			/** @var string|null $value */
			if (is_string($value)) {
				$value = trim($value);
			}

			if ($value === '' || $value === null) {
				$element->setFieldValue($fieldHandle, null);
				return;
			}

			// Parse the decimal string: a float multiply loses cents and assumes two subunits
			$moneyParser = new DecimalMoneyParser(new ISOCurrencies());
			$element->setFieldValue($fieldHandle, $moneyParser->parse((string) $value, new Currency($field->currency)));
		} elseif ($field instanceof DateField) {
			/** @var string|null $value */
			if (is_string($value)) {
				$value = trim($value);
			}

			if ($value === '' || $value === null) {
				$element->setFieldValue($fieldHandle, null);
				return;
			}

			$date = DateTimeHelper::toDateTime($value, true);
			if ($date !== false) {
				$element->setFieldValue($fieldHandle, $date);
			}
		} elseif ($field instanceof AssetsField) {
			if (! is_string($value)) {
				return;
			}

			// We're expecting a comma separated list of volume handles and asset paths in the format "volumeHandle:path/to/asset.jpg,volumeHandle:path/to/another/asset.jpg".
			$assetIds = collect(explode(',', $value))
				->map(static fn ($slug): string => trim($slug))
				->filter(static fn ($slug): bool => $slug !== '')
				->map(static fn ($slug): array => explode(':', $slug))
				->map(static function ($parts) {
					if (count($parts) === 1 && is_numeric($parts[0])) {
						return Craft::$app->assets->getAssetById((int) $parts[0])?->id;
					}

					$volumeHandle = $parts[0];
					$assetPath = $parts[1] ?? null;
					$volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

					if ($assetPath === null || ! $volume instanceof Volume) {
						Craft::warning("Skipped asset reference '{$volumeHandle}', which names no path or no volume", __METHOD__);
						return null;
					}

					$filename = basename($assetPath);
					$path = str_replace($filename, '', $assetPath);

					if ($path === '') {
						$folder = Craft::$app->assets->getRootFolderByVolumeId((int) $volume->id);
					} else {
						$path = rtrim($path, '/') . '/'; // Add a trailing slash to the folder path.
						$folder = Craft::$app->assets->findFolder([
							'volumeId' => $volume->id,
							'path' => $path,
						]);
					}

					if ($folder === null) {
						return null;
					}

					/** @var Asset|null $asset */
					$asset = Asset::find()->folderId($folder->id)->filename($filename)->one();

					return $asset?->id;
				})
				->all();

			$element->setFieldValue($fieldHandle, $assetIds);
		} elseif ($field instanceof BaseRelationField) {
			/** @var class-string<BaseRelationField> $fieldType */
			$fieldType = $field::class;
			/** @var class-string<Element> $elementType */
			$elementType = $fieldType::elementType();

			/** @var string|null $value */
			$slugs = explode(',', (string) $value);
			/** @var list<Element> $relatedElements */
			$relatedElements = $elementType::find()->slug($slugs)->all();
			$elementIds = collect($relatedElements)
				->map(static fn ($e): ?int => $e->id)
				->toArray();

			$element->setFieldValue($fieldHandle, $elementIds);
		} elseif ($field instanceof Lightswitch) {
			$element->setFieldValue($fieldHandle, $value === '1');
		} else {
			$element->setFieldValue($fieldHandle, $value);
		}
	}

	/**
	 * @param list<array{string, string}> $mapping
	 * @return list<mixed>
	 */
	private function normalizeProductExport(Product $product, array $mapping): array
	{
		$row = [];

		foreach ($mapping as [$fieldHandle, $heading]) {
			if ($fieldHandle === 'title') {
				$row[] = $product->title;
			} elseif ($fieldHandle === 'slug') {
				$row[] = $product->slug;
			} elseif ($fieldHandle === 'status') {
				$row[] = $product->enabled ? 'enabled' : 'disabled';
			} else {
				$value = $product->getFieldValue($fieldHandle);
				$value = $this->normalizeValue($value);

				$row[] = $value;
			}
		}

		return $row;
	}

	/**
	 * @return list<array{string, string}>
	 */
	private function resolveProductExportMapping(Product $product): array
	{
		$settings = Plugin::getInstance()->getSettings();
		$productTypeMapping = $settings->getProductFieldMapping($product->type->handle);

		$productMap = [];
		foreach (array_keys($productTypeMapping) as $heading) {
			$fieldHandle = $productTypeMapping[$heading];

			// CustomFieldBehavior keeps a deleted field's property, so only the layout says what getFieldValue can read
			if (! in_array($fieldHandle, self::NATIVE_PRODUCT_COLUMNS, true)
				&& ! $product->getFieldLayout()?->getFieldByHandle($fieldHandle) instanceof FieldInterface) {
				continue;
			}

			$productMap[] = [$fieldHandle, $heading];
		}

		$titleMap = collect($productMap)->filter(static fn ($mapping): bool => $mapping[0] === 'title')->first();
		if ($titleMap === null) {
			return array_merge([['title', 'title']], $productMap);
		}

		return $productMap;
	}
}
