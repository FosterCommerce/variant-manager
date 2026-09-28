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
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Typecast;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Site;
use craft\models\Volume;
use DateTimeInterface;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\errors\FieldMapException;
use fostercommerce\variantmanager\errors\ImportDataException;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\Plugin;
use Generator;
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
use Money\Exception\ParserException;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\db\Expression;

class Csv extends Component
{
	public const WRITE_MUTEX = 'variant-manager:write';

	/**
	 * Seconds to wait for the write lock, kept well under a queue job's default time limit.
	 */
	public const WRITE_MUTEX_WAIT = 60;

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

	/**
	 * The per-site values an empty cell resets to, and the promotable value a new variant starts with
	 */
	private const PER_SITE_DEFAULTS = [
		'availableForPurchase' => true,
		'promotable' => true,
		'freeShipping' => false,
		'inventoryTracked' => false,
		'minQty' => null,
		'maxQty' => null,
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
	 * The inventory totals an export writes and an import sets.
	 */
	private const INVENTORY_TOTALS = ['available', 'reserved', 'damaged', 'safety', 'qualityControl', 'committed'];

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
		$productTitle = reset($titleRecord);

		// Refuse an empty product title, because the title row's first cell names the product
		if ($this->isBlankCell($productTitle)) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.invalidProductTitle'));
		}

		$product = $this->resolveProductModel((string) $productTitle, self::productIdFromFilename($filename), $productTypeHandle);

		// Map by the product's own type, because a zip names one type for files that update products of several
		$mapping = $this->resolveVariantImportMapping($tabularDataReader, (string) $product->type->handle);

		if (($mapping['variant']['sku'] ?? null) === null) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.missingSkuColumn'));
		}

		$this->validateSharedStoreValues($mapping, $tabularDataReader);
		$this->validateInventoryQuantities($mapping, $tabularDataReader);

		// Write one import or Variant Maker run at a time, because two can each pass the SKU checks for the same new SKU
		$mutex = Craft::$app->getMutex();

		if (! $mutex->acquire(self::WRITE_MUTEX, self::WRITE_MUTEX_WAIT)) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.busy'));
		}

		try {
			$this->validateSkus($product, $mapping, $tabularDataReader, $refreshVariants);
			$this->writeImport($product, $titleRecord, $tabularDataReader, $mapping, $refreshVariants);
		} finally {
			$mutex->release(self::WRITE_MUTEX);
		}

		return $product;
	}

	/**
	 * The product ID an `ID__name.csv` filename names, or null for a file that creates a product.
	 */
	public static function productIdFromFilename(string $filename): ?int
	{
		return preg_match('/^(\d+)__/', basename($filename), $matches) === 1 ? (int) $matches[1] : null;
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
	 * @return array{filename: string, export: string}
	 * @throws CannotInsertRecord
	 * @throws CsvException
	 * @throws FieldMapException
	 */
	public function export(Product $product): array
	{
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
		// Compare SKUs ignoring letter case, as Commerce does
		return collect(Variant::find()->andWhere([
			'in',
			new Expression('LOWER([[commerce_purchasables.sku]])'),
			array_map(mb_strtolower(...), $items),
		])->status(null)->all())
			->groupBy(fn ($variant) => $variant->getOwner()->id)
			->map(static fn ($variants) => $variants->map(static fn ($variant) => $variant->sku)->all());
	}

	/**
	 * @param TabularDataReader<array<string, string|null>> $tabularDataReader
	 * @param array<string, string|null> $titleRecord
	 * @param array{variant: array<string, int|null>, attribute: array<int, array{int, string}>, sites: array<int, array{string, string}>, inventory: array<int, array{string, string}>, fieldHandle: string|null, variantFieldLayout: FieldLayout} $mapping
	 * @throws Throwable
	 */
	private function writeImport(Product $product, array $titleRecord, TabularDataReader $tabularDataReader, array $mapping, bool $refreshVariants): void
	{
		// Write in one transaction, because a deleted variant's purchasable row can't be restored
		Craft::$app->getDb()->transaction(function () use ($product, $titleRecord, $tabularDataReader, $mapping, $refreshVariants): void {
			$this->applyProductFields($product, $titleRecord);

			if ($product->isNewForSite) {
				$variants = $this->normalizeNewProductImport($tabularDataReader, $mapping);
			} else {
				if ($refreshVariants) {
					foreach (Variant::find()->product($product)->status(null)->all() as $replacedVariant) {
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
		});
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

		foreach ($this->variantRecords($reader) as $record) {
			foreach ($sites as $siteHandle => $data) {
				/** @var Variant|null $variant */
				$variant = Variant::find()->andWhere([
					'commerce_purchasables.sku' => $record[$skuColumn],
				])->site($siteHandle)->status(null)->one();
				if ($variant === null) {
					// The product is not propagated to this site, so the column has nowhere to write
					Craft::warning("Skipped per-site values for SKU {$record[$skuColumn]} on site {$siteHandle}", __METHOD__);
					continue;
				}

				foreach ($data as $fieldData) {
					$field = $fieldData['field'];

					if ($field === 'basePrice') {
						$variant->basePrice = $this->parsePrice($record[$fieldData['index']]);
						continue;
					}

					$cell = $record[$fieldData['index']];

					$properties = [
						$field => $this->isBlankCell($cell) ? (self::PER_SITE_DEFAULTS[$field] ?? null) : $cell,
					];

					Typecast::properties(Variant::class, $properties);

					$variant->{$field} = reset($properties);
				}

				// Fail the import on a variant that won't save, so the transaction rolls back every site
				if (! Craft::$app->elements->saveElement($variant)) {
					$errors = $variant->getErrorSummary(false);
					throw new ImportDataException($errors[0] ?? Craft::t('variant-manager', 'import.variantSaveFailed'));
				}
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
		foreach ($this->variantRecords($reader) as $record) {
			/** @var Variant|null $variant */
			$variant = Variant::find()->andWhere([
				'commerce_purchasables.sku' => $record[$skuColumn],
			])->status(null)->one();

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
					$value[1] => $this->isBlankCell($record[$index]) ? 0 : (int) trim((string) $record[$index]),
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
	 * Refuse different values for two sites sharing a store, since the later site's value would replace the first.
	 *
	 * Skip empty cells, because an export leaves them empty for a site the product doesn't exist on.
	 *
	 * @param array{variant: array<string, int|null>, sites: array<int, array{0: string, 1: string}>} $mapping
	 * @param TabularDataReader<array<string, string|null>> $tabularDataReader
	 * @throws ImportDataException
	 */
	private function validateSharedStoreValues(array $mapping, TabularDataReader $tabularDataReader): void
	{
		/** @var CommercePlugin $commerce */
		$commerce = CommercePlugin::getInstance();
		$indexesByFieldAndStore = [];

		foreach ($mapping['sites'] as $index => [$field, $siteHandle]) {
			$siteId = Craft::$app->getSites()->getSiteByHandle($siteHandle)?->id;
			$storeId = $commerce->getStores()->getStoreBySiteId((int) $siteId)?->id;
			$indexesByFieldAndStore["{$field}\0{$storeId}"][] = $index;
		}

		/** @var int $skuColumn */
		$skuColumn = $mapping['variant']['sku'];

		foreach ($this->variantRecords($tabularDataReader) as $record) {
			foreach ($indexesByFieldAndStore as $fieldAndStore => $indexes) {
				$isPrice = str_starts_with($fieldAndStore, "basePrice\0");
				$filledIndexes = array_filter($indexes, fn (int $index): bool => ! $this->isBlankCell($record[$index]));
				// Compare prices by value, so 10 and 10.00 agree
				$values = array_unique(array_map(fn (int $index): string => $isPrice
					? (string) $this->parsePrice($record[$index])
					: strtolower(trim((string) $record[$index])), $filledIndexes));

				if (count($values) > 1) {
					throw new ImportDataException(Craft::t('variant-manager', 'import.storeConflict', [
						'sku' => $record[$skuColumn],
					]));
				}
			}
		}
	}

	/**
	 * Refuse a quantity that isn't a whole number, since the inventory update takes an integer.
	 *
	 * @param array{inventory: array<int, array{string, string}>} $mapping
	 * @param TabularDataReader<array<string, string|null>> $tabularDataReader
	 * @throws ImportDataException
	 */
	private function validateInventoryQuantities(array $mapping, TabularDataReader $tabularDataReader): void
	{
		foreach ($this->variantRecords($tabularDataReader) as $record) {
			foreach (array_keys($mapping['inventory']) as $index) {
				if (! $this->isBlankCell($record[$index]) && preg_match('/^-?\d+$/', trim((string) $record[$index])) !== 1) {
					throw new ImportDataException(Craft::t('variant-manager', 'import.invalidQuantity', [
						'quantity' => $record[$index],
					]));
				}
			}
		}
	}

	/**
	 * The variant's attribute pairs after this row, keeping the attributes the CSV has no column for.
	 *
	 * @param array{attribute: array<int, array{int, string}>, fieldHandle: string|null} $mapping
	 * @param array<int, string|null> $record
	 * @return list<array{attributeName: string, attributeValue: string}>
	 */
	private function importedPairs(Variant $variantElement, array $mapping, array $record): array
	{
		$pairs = [];
		$storedPairs = $variantElement->id === null ? [] : $variantElement->getFieldValue((string) $mapping['fieldHandle']);

		foreach (is_array($storedPairs) ? $storedPairs : [] as $storedPair) {
			$pairs[VariantAttribute::normalizeName((string) ($storedPair['attributeName'] ?? ''))] = $storedPair;
		}

		foreach ($mapping['attribute'] as [$index, $attributeName]) {
			$value = trim((string) $record[$index]);

			$pairs[VariantAttribute::normalizeName($attributeName)] = [
				'attributeName' => $attributeName,
				'attributeValue' => $value === '' ? Plugin::getInstance()->getSettings()->emptyAttributeValue : $value,
			];
		}

		return array_values($pairs);
	}

	/**
	 * The rows after the product's title row, as lists aligned with the header.
	 *
	 * @param TabularDataReader<array<string, string|null>> $reader
	 * @return Generator<list<string|null>>
	 */
	private function variantRecords(TabularDataReader $reader): Generator
	{
		$isTitleRow = true;

		foreach ($reader->getRecords() as $record) {
			// Skip the first record by position, because a blank line before it shifts its offset
			if ($isTitleRow) {
				$isTitleRow = false;
				continue;
			}

			/** @var list<string|null> $record */
			$record = array_values($record);

			// Skip a row of empty cells, which spreadsheet apps write as a line of commas
			if (array_filter($record, fn (mixed $cell): bool => ! $this->isBlankCell($cell)) === []) {
				continue;
			}

			yield $record;
		}
	}

	/**
	 * The value an empty cell clears a variant field to: on for enabled, off for isDefault, and empty otherwise.
	 */
	private function emptyVariantValue(string $fieldHandle): mixed
	{
		return match ($fieldHandle) {
			'enabled' => true,
			'isDefault' => false,
			default => null,
		};
	}

	private function isBlankCell(mixed $cell): bool
	{
		return ! is_string($cell) || trim($cell) === '';
	}

	/**
	 * @throws ImportDataException if the cell is empty or isn't a number, because Commerce requires a price
	 */
	private function parsePrice(?string $price): float
	{
		$price = trim((string) $price);

		if ($price === '') {
			throw new ImportDataException(Craft::t('variant-manager', 'import.missingPrice'));
		}

		// Read the documented format without a locale, because the queue runs in the locale of whoever triggered it
		if (preg_match('/^-?(\d{1,3}(,\d{3})+|\d+)(\.\d+)?$/', $price) !== 1) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.invalidPrice', [
				'price' => $price,
			]));
		}

		return (float) str_replace(',', '', $price);
	}

	/**
	 * @param array{variant: array<string, int|null>, sites: array<int, array{string, string}>} $mapping
	 * @param TabularDataReader<array<string, string|null>> $tabularDataReader
	 * @throws ImportDataException
	 */
	private function validateSkus(Product $product, array $mapping, TabularDataReader $tabularDataReader, bool $refreshVariants): void
	{
		/** @var int $skuColumn */
		$skuColumn = $mapping['variant']['sku'];

		$skus = [];

		foreach ($this->variantRecords($tabularDataReader) as $record) {
			// Refuse an empty SKU on a variant row, because Commerce requires one on every variant
			if ($this->isBlankCell($record[$skuColumn])) {
				throw new ImportDataException(Craft::t('variant-manager', 'import.missingSku'));
			}

			$skus[] = (string) $record[$skuColumn];
		}

		// Compare SKUs ignoring letter case, as Commerce does
		$countedSkus = array_count_values(array_map(mb_strtolower(...), $skus));
		$duplicateSkus = array_filter($skus, static fn (string $sku): bool => $countedSkus[mb_strtolower($sku)] > 1);
		if ($duplicateSkus !== []) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.duplicateSkus', [
				'skus' => implode(', ', array_unique($duplicateSkus)),
			]));
		}

		/** @var Collection<array-key, string[]> $foundSkus */
		$foundSkus = $this->findProductVariantSkus($skus);

		if ($product->isNewForSite && ! $foundSkus->isEmpty()) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.skusExist', [
				'skus' => implode(', ', $foundSkus->flatten()->values()->all()),
			]));
		}

		// Count every row as new under Replace all variants, because the import deletes the existing variants first
		$ownSkus = $refreshVariants || $product->id === null ? [] : array_map(mb_strtolower(...), $foundSkus->get($product->id) ?? []);
		$foundSkus = $foundSkus->filter(static fn ($_value, $key): bool => $key !== $product->id);
		if (! $foundSkus->isEmpty()) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.skusExistElsewhere', [
				'skus' => implode(', ', $foundSkus->flatten()->values()->all()),
			]));
		}

		// Refuse new variants without a price column, because variants save without validation
		$createsVariants = array_diff(array_map(mb_strtolower(...), $skus), $ownSkus) !== [];

		if ($createsVariants && ! in_array('basePrice', array_column($mapping['sites'], 0), true)) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.missingPriceColumn'));
		}
	}

	/**
	 * @return TabularDataReader<array<string, string|null>>
	 * @throws CsvException
	 * @throws ImportDataException
	 */
	private function read(string $csvData): TabularDataReader
	{
		// Refuse other encodings, such as a Windows-1252 export from Excel, because names are stored as UTF-8
		if (! mb_check_encoding($csvData, 'UTF-8')) {
			throw new ImportDataException(Craft::t('variant-manager', 'import.notUtf8'));
		}

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
		foreach ($this->variantRecords($tabularDataReader) as $record) {
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
		$existingVariants = collect(Variant::find()->product($product)->status(null)->all());
		$newVariants = [];
		foreach ($this->variantRecords($tabularDataReader) as $record) {
			// Compare as lowercased strings, because Commerce ignores letter case and PHP compares numeric strings as numbers
			$sku = mb_strtolower((string) $record[$mapping['variant']['sku']]);
			$variantId = $existingVariants->first(static fn (Variant $existingVariant): bool => mb_strtolower((string) $existingVariant->sku) === $sku)->id ?? 0;
			$newVariants[] = $this->normalizeVariantImport($record, $mapping, $variantId);
		}

		// Match on id. Two variants can share a title.
		$importedVariantIds = collect($newVariants)->pluck('id')->filter()->all();
		$removedVariants = $existingVariants->reject(static fn (Variant $existingVariant): bool => in_array($existingVariant->id, $importedVariantIds, true));
		foreach ($removedVariants as $removedVariant) {
			Craft::$app->elements->deleteElement($removedVariant);
		}

		return $newVariants;
	}

	/**
	 * @param list<string|null> $record
	 * @param array{variant: array<string, int|null>, attribute: array<int, array{int, string}>, fieldHandle: string|null, variantFieldLayout: FieldLayout} $mapping
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function normalizeVariantImport(array $record, array $mapping, int $variantId): Variant
	{
		if ($variantId !== 0) {
			/** @var Variant $variantElement */
			$variantElement = Variant::find()->id($variantId)->status(null)->one();
		} else {
			$variantElement = new Variant();
			// Start a new variant promotable, where Commerce would leave it not promotable
			$variantElement->promotable = self::PER_SITE_DEFAULTS['promotable'];
		}

		$mapped = [];
		$fields = [];

		// Leave the field alone when the CSV has no attribute columns, the same as any other missing column
		if (! empty($mapping['fieldHandle']) && $mapping['attribute'] !== []) {
			$fields[$mapping['fieldHandle']] = $this->importedPairs($variantElement, $mapping, $record);
		}

		foreach ($mapping['variant'] as $fieldHandle => $index) {
			if ($index === null) {
				continue;
			}

			$value = $this->isBlankCell($record[$index]) ? $this->emptyVariantValue($fieldHandle) : $record[$index];

			if (in_array($fieldHandle, self::STANDARD_VARIANT_FIELDS, true)) {
				$mapped[$fieldHandle] = $value;
			} else {
				$fields[$fieldHandle] = $value;
			}
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
	 * @throws ImportDataException
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
			$matchedVariantFieldMap = array_filter($variantSiteMap, static fn ($mapping): bool => strcasecmp($heading, $mapping) === 0 || stripos($heading, $mapping . '[') === 0, ARRAY_FILTER_USE_KEY);

			// Refuse a header two map keys share, because case-insensitive matching can't choose between them
			if (count($matchedCrossSiteFieldMap) + count($matchedVariantFieldMap) > 1) {
				throw new ImportDataException(Craft::t('variant-manager', 'import.ambiguousColumn', [
					'heading' => $heading,
					'columns' => implode(', ', [...array_keys($matchedCrossSiteFieldMap), ...array_keys($matchedVariantFieldMap)]),
				]));
			}

			if ($matchedCrossSiteFieldMap !== []) {
				$variantFieldHandle = current($matchedCrossSiteFieldMap);

				// Refuse a second column for the same field, because the later one would replace the first
				if (isset($variantMap[$variantFieldHandle])) {
					throw new ImportDataException(Craft::t('variant-manager', 'import.repeatedColumn', [
						'heading' => $heading,
						'field' => $variantFieldHandle,
					]));
				}

				$variantMap[$variantFieldHandle] = $i;
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

				if (in_array([$value, $matches[1]], $sitesMap, true)) {
					throw new ImportDataException(Craft::t('variant-manager', 'import.repeatedColumn', [
						'heading' => $heading,
						'field' => "{$value}[{$matches[1]}]",
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

				if (! in_array($totalHandle, self::INVENTORY_TOTALS, true)) {
					throw new ImportDataException(Craft::t('variant-manager', 'import.unknownInventoryTotal', [
						'heading' => $heading,
						'totals' => implode(', ', self::INVENTORY_TOTALS),
					]));
				}

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
	 * @throws ImportDataException
	 */
	private function resolveProductModel(string $title, ?int $productId, ?string $productTypeHandle): Product
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
			$productType = $productTypeHandle === null ? null : $plugin->getProductTypes()->getProductTypeByHandle($productTypeHandle);

			if (! $productType instanceof ProductType) {
				throw new ImportDataException(Craft::t('variant-manager', 'import.invalidProductTypeHandle'));
			}

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

			// Leave the site's cells empty where the product isn't propagated, because no variant exists there to read
			if ($siteVariant instanceof Variant) {
				foreach ($siteMapping as $key => $value) {
					$siteMapping[$key] = $this->normalizeValue($siteVariant->{$key});
				}
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
				foreach ($variants as $variant) {
					foreach ($variant->{$fieldHandle} ?? [] as $attribute) {
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
		$settings = Plugin::getInstance()->getSettings();
		$productFieldMapping = $settings->getProductFieldMapping($product->type->handle);

		// Match product headers regardless of letter case, the same as variant headers
		$fieldHandlesByHeading = array_change_key_case($productFieldMapping);
		$valuesByFieldHandle = [];

		foreach ($titleRecord as $heading => $value) {
			$fieldHandle = $fieldHandlesByHeading[strtolower(trim((string) $heading))] ?? null;

			if ($fieldHandle === null) {
				continue;
			}

			if ($this->isBlankCell($value)) {
				$value = null;
			}

			// Refuse a second column for the same field, because the later one would replace the first
			if (array_key_exists($fieldHandle, $valuesByFieldHandle)) {
				throw new ImportDataException(Craft::t('variant-manager', 'import.repeatedColumn', [
					'heading' => $heading,
					'field' => $fieldHandle,
				]));
			}

			$valuesByFieldHandle[$fieldHandle] = $value;
		}

		collect($valuesByFieldHandle)
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
				$entry = Entry::find()->slug(Db::escapeParam($slug))->section($sectionHandle)->one();
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

			if ($value === null) {
				$element->setFieldValue($fieldHandle, null);
				return;
			}

			// Parse the decimal string: a float multiply loses cents and assumes two subunits
			$moneyParser = new DecimalMoneyParser(new ISOCurrencies());

			try {
				$element->setFieldValue($fieldHandle, $moneyParser->parse((string) $value, new Currency($field->currency)));
			} catch (ParserException) {
				throw new ImportDataException(Craft::t('variant-manager', 'import.invalidMoney', [
					'value' => $value,
					'field' => $fieldHandle,
				]));
			}
		} elseif ($field instanceof DateField) {
			/** @var string|null $value */
			if (is_string($value)) {
				$value = trim($value);
			}

			if ($value === null) {
				$element->setFieldValue($fieldHandle, null);
				return;
			}

			$date = DateTimeHelper::toDateTime($value, true);
			if ($date !== false) {
				$element->setFieldValue($fieldHandle, $date);
			}
		} elseif ($field instanceof AssetsField) {
			if (! is_string($value)) {
				$element->setFieldValue($fieldHandle, []);
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
					$asset = Asset::find()->folderId($folder->id)->filename(Db::escapeParam($filename))->one();

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
			$relatedElements = $elementType::find()->slug(array_map(Db::escapeParam(...), $slugs))->all();
			$elementIds = collect($relatedElements)
				->map(static fn ($e): ?int => $e->id)
				->toArray();

			$element->setFieldValue($fieldHandle, $elementIds);
		} elseif ($field instanceof Lightswitch) {
			// Accept the same on and off words as the built-in on/off columns
			$element->setFieldValue($fieldHandle, $value === null ? $field->default : App::normalizeBooleanValue($value) === true);
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

		// Write the title column first, because an import reads the product title from row 2's first cell
		$titleMaps = array_filter($productMap, static fn (array $mapping): bool => $mapping[0] === 'title');
		$otherMaps = array_filter($productMap, static fn (array $mapping): bool => $mapping[0] !== 'title');

		return [...($titleMaps === [] ? [['title', 'title']] : array_values($titleMaps)), ...array_values($otherMaps)];
	}
}
