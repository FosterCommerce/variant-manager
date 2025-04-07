<?php

namespace fostercommerce\variantmanager\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\commerce\collections\UpdateInventoryLevelCollection;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\enums\InventoryUpdateQuantityType;
use craft\commerce\models\inventory\UpdateInventoryLevel;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as CommercePlugin;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\errors\ElementNotFoundException;
use craft\fields\Assets as AssetsField;
use craft\fields\Entries;
use craft\fields\Lightswitch;
use craft\fields\Money as MoneyField;
use craft\helpers\ElementHelper;
use craft\helpers\Typecast;
use craft\models\Site;
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
	 * @throws CsvException
	 * @throws Exception
	 * @throws InvalidConfigException
	 * @throws UnableToProcessCsv
	 * @throws ElementNotFoundException
	 * @throws \Throwable
	 */
	public function import(string $filename, string $csvData, ?string $productTypeHandle, bool $refreshVariants = false): Product
	{
		$tabularDataReader = $this->read($csvData);
		$titleRecord = array_filter($tabularDataReader->nth(0), static fn ($value) => $value !== null);
		if ($titleRecord === []) {
			throw new \RuntimeException('Invalid product title');
		}

		$productId = explode('__', $filename)[0] ?? null;
		if (! ctype_digit((string) $productId)) {
			$productId = null;
		}

		$product = $this->resolveProductModel($titleRecord[array_key_first($titleRecord)], $productId, $productTypeHandle);

		if ($productTypeHandle === null) {
			$productTypeHandle = $product->type->handle;
		}

		$mapping = $this->resolveVariantImportMapping($tabularDataReader, $productTypeHandle);

		$this->validateSkus($product, $mapping, $tabularDataReader);

		$this->applyProductFields($product, $titleRecord);

		if ($product->isNewForSite) {
			$variants = $this->normalizeNewProductImport($tabularDataReader, $mapping);
		} else {
			if ($refreshVariants) {
				$variants = Variant::find()->product($product)->all();
				foreach ($variants as $variant) {
					Craft::$app->elements->deleteElement($variant);
				}
			}

			$variants = $this->normalizeExistingProductImport($product, $tabularDataReader, $mapping);
		}

		$product->setVariants($variants);
		// runValidation needs to be `true` so that updateTitle and updateSku are run against Variants.
		// See: https://github.com/craftcms/commerce/pull/3297
		if (! Craft::$app->elements->saveElement($product, false, true, true)) {
			$errors = $product->getErrorSummary(false);
			$error = reset($errors);
			throw new \RuntimeException($error ?? 'Failed to save product');
		}

		// Save after product has been saved so that titles can be generated correctly if necessary.
		foreach ($variants as $variant) {
			$variant->setOwner($product);
			if (! Craft::$app->elements->saveElement($variant, false, true, true)) {
				$errors = $variant->getErrorSummary(false);
				$error = reset($errors);
				throw new \RuntimeException($error ?? 'Failed to save product');
			}
		}

		if (! Craft::$app->elements->saveElement($product, false, true, true)) {
			$errors = $product->getErrorSummary(false);
			$error = reset($errors);
			throw new \RuntimeException($error ?? 'Failed to save product');
		}

		$this->importSiteSpecificData($tabularDataReader, $mapping['variant']['sku'], $mapping['sites']);
		$this->importInventoryLevels($tabularDataReader, $mapping['variant']['sku'], $mapping['inventory']);

		return $product;
	}

	/**
	 * @throws CannotInsertRecord
	 * @throws CsvException
	 */
	public function export(string $productId): array|bool
	{
		/** @var Product|null $product */
		$product = Product::find()->id($productId)->one();

		if (! isset($product)) {
			return false;
		}

		return [
			'filename' => "{$product->id}__{$product->slug}",
			'export' => $this->exportProduct($product, Variant::find()->product($product)->all()),
		];
	}

	/**
	 * @throws CannotInsertRecord
	 * @throws CsvException
	 */
	public function exportProduct(Product $product, array $variants): string
	{
		$sites = Craft::$app->sites->allSites;
		$mapping = $this->resolveVariantExportMapping($product, $sites);
		$productMapping = $this->resolveProductExportMapping($product);

		$writer = Writer::createFromString();

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

		$productHeaders = array_map(static fn ($fieldMap) => $fieldMap[1], $productMapping);

		// Order:
		// 1. Product field mapping
		// 2. Variant field mapping (This may change if fields are set per site in the future)
		// 3. Commerce-specific variant fields which are different per site
		// 4. Inventory fields for each inventory location
		// 5. Variant Attribute fields
		$header = array_merge(
			$productHeaders,
			array_map(
				static fn ($fieldMap) => $fieldMap[1],
				$mapping['variant']
			),
			$sitesHeaders,
			$inventoryHeaders,
			$mapping['attribute']
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
			// We need to make sure that the variant columns that share a name with product columns are not duplicated.
			// This line of code removes it by creating an associative array first using the header values as keys and then converting it back to an indexed array.
			$row = array_values(array_combine($header, $row));
			$writer->insertOne($row);
		}

		return $writer->toString();
	}

	/**
	 * @param string[] $items
	 * @throws InvalidConfigException
	 */
	protected function findProductVariantSkus(array $items): array
	{
		$found = Variant::find()
			->sku($items)
			->all();

		$mapped = [];
		foreach ($found as $variant) {
			/** @var Product $product */
			$product = $variant->getOwner();
			if (! array_key_exists($product->id, $mapped)) {
				$mapped[$product->id] = [];
			}

			$mapped[$product->id][] = $variant->sku;
		}

		return $mapped;
	}

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
				$variant = Variant::find()->sku($record[$skuColumn])->site($siteHandle)->one();

				if ($data->firstWhere('field', 'availableForPurchase') === null) {
					// If the availableForPurchase field does not exist, then we will default it to true
					$variant->availableForPurchase = true;
				}

				foreach ($data as $fieldData) {
					$field = $fieldData['field'];
					$properties = [
						$field => $record[$fieldData['index']],
					];

					if ($field === 'availableForPurchase') {
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

			$variant = Variant::find()->sku($record[$skuColumn])->one();

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

			CommercePlugin::getInstance()->getInventory()->executeUpdateInventoryLevels(UpdateInventoryLevelCollection::make($updates));
		}
	}

	/**
	 * @throws UnableToProcessCsv
	 */
	private function validateSkus(Product $product, array $mapping, TabularDataReader $tabularDataReader): void
	{
		// Exit early if there are duplicate SKUs
		$skuColumn = $mapping['variant']['sku'];
		$skus = iterator_to_array($tabularDataReader->fetchColumnByOffset($skuColumn));

		$countedSkus = array_count_values($skus);
		$duplicateSkus = array_filter($countedSkus, static fn ($count): bool => $count > 1);
		if ($duplicateSkus !== []) {
			throw new \RuntimeException('Duplicate SKUs found');
		}

		$foundSkus = $this->findProductVariantSkus($skus);

		// If the product is a new product and the SKU exists already, return an error.
		if ($product->isNewForSite && $foundSkus !== []) {
			throw new \RuntimeException('One or more SKUs already exist');
		}

		// If the SKU already exists for a different product return an error.
		if (array_filter($foundSkus, static fn ($key): bool => $key !== $product->id, ARRAY_FILTER_USE_KEY) !== []) {
			throw new \RuntimeException('One or more SKUs already exist on different products');
		}
	}

	/**
	 * @throws CsvException
	 */
	private function read(string $csvData): TabularDataReader
	{
		$reader = Reader::createFromString($csvData);
		$reader->setHeaderOffset(0);
		return (new Statement())->process($reader);
	}

	/**
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
	 * @return Variant[]
	 * @throws InvalidConfigException
	 */
	private function normalizeExistingProductImport(Product $product, TabularDataReader $tabularDataReader, array $mapping): array
	{
		$iterator = $tabularDataReader->getIterator();
		$existingVariants = collect(Variant::find()->product($product)->all());
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

			$variant = $existingVariants->firstWhere('sku', $record[$mapping['variant']['sku']])->id ?? 0;
			$newVariants[] = $this->normalizeVariantImport($record, $mapping, $variant);
		}


		$removedVariants = $existingVariants->diff($newVariants);
		foreach ($removedVariants as $variant) {
			// Remove variants that weren't in the import.
			Craft::$app->elements->deleteElement($variant);
		}

		return $newVariants;
	}

	/**
	 * @throws InvalidConfigException
	 * @throws \Throwable
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
			$variantElement = Variant::find()->id($variantId)->one();
		} else {
			$variantElement = new Variant();
		}

		Typecast::properties(Variant::class, $mapped);
		foreach ($mapped as $fieldHandle => $value) {
			$variantElement->{$fieldHandle} = $value;
		}

		foreach ($fields as $fieldHandle => $value) {
			$this->setFieldValue($variantElement, $fieldHandle, $value);
		}

		return $variantElement;
	}

	private function resolveVariantImportMapping(TabularDataReader $tabularDataReader, string $productTypeHandle): array
	{
		$settings = Plugin::getInstance()->getSettings();
		$attributePrefix = $settings->attributePrefix;
		$inventoryPrefix = $settings->inventoryPrefix;
		$productTypeMap = $settings->getProductTypeMapping($productTypeHandle);
		$productType = CommercePlugin::getInstance()->productTypes->getProductTypeByHandle($productTypeHandle);

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
			throw new \RuntimeException('Invalid product type handle');
		}

		$fieldHandle = FieldHelper::getFirstVariantAttributesField($productType->getVariantFieldLayout())?->handle;

		$variantMap = array_fill_keys(array_values($productTypeMap), null);

		$attributeMap = [];
		$inventoryMap = [];
		$sitesMap = [];
		foreach ($tabularDataReader->getHeader() as $i => $heading) {
			$heading = trim($heading);
			$matchedCrossSiteFieldMap = array_filter($crossSiteProductTypeMap, static fn ($mapping): bool => $heading === $mapping, ARRAY_FILTER_USE_KEY);
			$matchedVariantFieldMap = array_filter($variantSiteMap, static fn ($mapping): bool => str_starts_with($heading, (string) $mapping), ARRAY_FILTER_USE_KEY);

			if ($matchedCrossSiteFieldMap !== []) {
				$variantMap[$productTypeMap[$heading]] = $i;
			} elseif ($matchedVariantFieldMap !== []) {
				$key = array_key_first($matchedVariantFieldMap);
				$value = $matchedVariantFieldMap[$key];
				$pattern = "/{$key}\[(.*?)\]$/";
				preg_match($pattern, $heading, $matches);
				$siteHandle = $matches[1];
				$sitesMap[$i] = [$value, $siteHandle];
			} elseif (str_starts_with($heading, $inventoryPrefix)) {
				$pattern = "/{$inventoryPrefix}\[(.*?)\]:\s(.*?)$/";
				preg_match($pattern, $heading, $matches);
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
		];
	}

	/**
	 * @throws InvalidConfigException
	 */
	private function resolveProductModel(string $title, ?string $productId, ?string $productTypeHandle): Product
	{
		if ($productId !== null) {
			$product = Product::find()->id($productId)->one();
			if ($product === null) {
				throw new \RuntimeException('Invalid product id');
			}
		} else {
			$product = new Product();
			$product->isNewForSite = true;
			$product->slug = ElementHelper::generateSlug($title);

			/** @var CommercePlugin $plugin */
			$plugin = Craft::$app->plugins->getPlugin('commerce');
			$product->typeId = $plugin->getProductTypes()->getProductTypeByHandle($productTypeHandle)->id;
		}

		$product->title = $title;

		return $product;
	}

	private function valueMapFromMapping($map, mixed $defaultValue = ''): array
	{
		return array_map(
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
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}

		return $value;
	}

	/**
	 * @param Site[] $sites
	 */
	private function normalizeVariantExport(Variant $variant, array $mapping, array $sites): array
	{
		$row = [];

		// Add variant fields
		foreach ($mapping['variant'] as [$fieldHandle, $header]) {
			if ($fieldHandle === 'stock' && $variant->inventoryTracked) {
				// If inventory tracking is enabled, we don't want to set the stock field, because the inventory will manage stock levels.
				$row[] = '';
				continue;
			}

			$row[] = $this->normalizeValue($variant->{$fieldHandle});
		}

		// Map variant values per site
		$mappedSiteValues = $this->valueMapFromMapping($mapping['sites']);
		foreach ($sites as $site) {
			$siteVariant = Variant::find()->id($variant->id)->site($site)->one();
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
			$attributes = $variant->{$handle};
			foreach ($attributes ?? [] as $attribute) {
				$row[] = $attribute['attributeValue'];
			}
		}

		return $row;
	}

	/**
	 * @param Site[] $sites
	 * @throws InvalidConfigException
	 */
	private function resolveVariantExportMapping(Product $product, array $sites): array
	{
		$settings = Plugin::getInstance()->getSettings();
		$attributePrefix = $settings->attributePrefix;
		$inventoryPrefix = $settings->inventoryPrefix;

		$productTypeMapping = $settings->getProductTypeMapping($product->type->handle);

		$variantMap = [];
		$commerceVariantFieldMap = array_combine(self::STANDARD_PER_SITE_VARIANT_FIELDS, self::STANDARD_PER_SITE_VARIANT_FIELDS);

		foreach (array_keys($productTypeMapping) as $i => $heading) {
			$fieldHandle = $productTypeMapping[$heading];
			if (array_key_exists($fieldHandle, $commerceVariantFieldMap)) {
				$commerceVariantFieldMap[$fieldHandle] = $heading;
			} else {
				$variantMap[$i] = [$fieldHandle, $heading];
			}
		}

		$fieldHandle = null;
		$attributeMap = [];
		$inventoryMap = [];
		$mappedSites = [];
		if ($product->variants !== []) {
			// Get a variant that has tracked inventory so that we can get the inventory levels
			$variant = Variant::find()->product($product)->inventoryTracked()->one();
			if ($variant === null) {
				// Otherwise get any variant.
				$variant = Variant::find()->product($product)->one();
			}

			$fieldHandle = FieldHelper::getFirstVariantAttributesField($variant->getFieldLayout())?->handle;
			if ($fieldHandle !== null) {
				foreach ($variant->{$fieldHandle} ?? [] as $attribute) {
					$attributeMap[] = $attributePrefix . $attribute['attributeName'];
				}
			}

			/** @var Collection<string> $inventoryLocations */
			$inventoryLocations = CommercePlugin::getInstance()->getInventoryLocations()->getAllInventoryLocations()->map(static fn ($l) => $l->handle);
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

	private function applyProductFields(Product $product, array $titleRecord): void
	{
		if (empty($titleRecord)) {
			return;
		}

		$settings = Plugin::getInstance()->getSettings();
		$productTypeMapping = array_values($settings->getProductFieldMapping($product->type->handle));

		collect($titleRecord)
			->only($productTypeMapping)
			->filter(static fn ($value, $fieldHandle) => $fieldHandle !== 'title')
			->each(function (mixed $value, string $fieldHandle) use ($product) {
				if ($fieldHandle === 'slug') {
					$product->slug = $value;
					return;
				}

				$this->setFieldValue($product, $fieldHandle, $value);
			});
	}

	private function setFieldValue(Element $element, string $fieldHandle, mixed $value): void
	{
		$field = $element->getFieldLayout()?->getFieldByHandle($fieldHandle);
		if ($field instanceof Entries) {
			$sectionUids = $field->sources === '*'
				? []
				: array_map(static fn ($source) => str_replace('section:', '', $source), $field->sources);
			$sectionHandles = array_map(static fn ($uid) => Craft::$app->entries->getSectionByUid($uid)?->handle, $sectionUids);

			// We have to assume that the value is an array of slugs
			$slugs = collect(explode(',', $value))->map(static fn ($slug) => explode(':', $slug))->all();
			$entries = [];
			foreach ($slugs as $slug) {
				$sectionHandle = $slug[0] ?? null;
				$slug = $slug[1] ?? null;

				if ($sectionHandle === null || $slug === null) {
					continue;
				}


				if ($sectionUids !== [] && ! in_array($sectionHandle, $sectionHandles, true)) {
					// If the field defines sections, and the section is not in the list of allowed sections, skip.
					continue;
				}

				$entry = Entry::find()->slug($slug)->section($sectionHandle)->one();
				if ($entry === null) {
					// If the entry is not found, skip.
					continue;
				}

				$entries[] = $entry->id;
			}

			$element->setFieldValue($fieldHandle, $entries);
		} elseif ($field instanceof MoneyField) {
			if (is_string($value)) {
				$value = trim($value);
			}

			if ($value === '' || $value === null) {
				$element->setFieldValue($fieldHandle, null);
				return;
			}

			// Money takes values like 15.00 and turns it into 0.15, so we need to give it the value in cents.
			$value = (int) ($value * 100);
			$element->setFieldValue($fieldHandle, new Money($value, new Currency($field->currency)));
		} elseif ($field instanceof AssetsField) {
			if (! is_string($value)) {
				return;
			}
			// We're expecting a comma separated list of volume handles and asset paths in the format "volumeHandle:path/to/asset.jpg,volumeHandle:path/to/another/asset.jpg".
			$assetIds = collect(explode(',', $value))
				->map(static fn ($slug) => explode(':', $slug))
				->map(static function ($parts) {
					if (count($parts) === 1 && is_numeric($parts[0])) {
						return Craft::$app->assets->getAssetById((int) $parts[0])?->id;
					}

					$volumeHandle = $parts[0] ?? null;
					$assetPath = $parts[1] ?? null;

					$volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

					$filename = basename($assetPath);
					$path = str_replace($filename, '', $assetPath);

					if ($path === '') {
						$folder = Craft::$app->assets->getRootFolderByVolumeId($volume->id);
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

					$asset = Asset::find()->folderId($folder->id)->filename($filename)->one();

					return $asset?->id;
				})
				->all();

			$element->setFieldValue($fieldHandle, $assetIds);
		} elseif ($field instanceof Lightswitch) {
			$element->setFieldValue($fieldHandle, $value === '1');
		} else {
			$element->setFieldValue($fieldHandle, $value);
		}
	}

	private function normalizeProductExport(Product $product, array $mapping): array
	{
		$row = [];

		foreach ($mapping as [$fieldHandle, $heading]) {
			if ($fieldHandle === 'title') {
				$row[] = $product->title;
			} elseif ($fieldHandle === 'slug') {
				$row[] = $product->slug;
			} else {
				$value = $product->getFieldValue($fieldHandle);
				if ($value instanceof EntryQuery) {
					$value = collect($value->all())
						->map(static fn ($element) => "{$element->section->handle}:{$element->slug}")
						->join(',');
				} elseif ($value instanceof AssetQuery) {
					$value = collect($value->all())
						->map(static fn ($asset) => "{$asset->volume->handle}:{$asset->path}")
						->join(',');
				} elseif ($value instanceof Money) {
					$formatter = new DecimalMoneyFormatter(new ISOCurrencies());
					$value = $formatter->format($value);
				} else {
					$value = $this->normalizeValue($value);
				}

				$row[] = $value;
			}
		}

		return $row;
	}

	private function resolveProductExportMapping(Product $product): array
	{
		$settings = Plugin::getInstance()->getSettings();
		$productTypeMapping = $settings->getProductFieldMapping($product->type->handle);

		$productMap = [];
		foreach (array_keys($productTypeMapping) as $i => $heading) {
			$fieldHandle = $productTypeMapping[$heading];
			$productMap[$i] = [$fieldHandle, $heading];
		}

		$titleMap = collect($productMap)->filter(static fn ($mapping) => $mapping[1] === 'title')->first();
		if ($titleMap === null) {
			$productMap = array_merge([['title', 'title']], $productMap);
		}

		return $productMap;
	}
}
