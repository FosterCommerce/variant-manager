<?php

namespace fostercommerce\variantmanager\services;

use Craft;
use craft\base\Component;
use craft\commerce\collections\UpdateInventoryLevelCollection;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\enums\InventoryUpdateQuantityType;
use craft\commerce\models\inventory\UpdateInventoryLevel;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as CommercePlugin;
use craft\errors\ElementNotFoundException;
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
use yii\base\Exception;
use yii\base\InvalidConfigException;

class Csv extends Component
{
    private const STANDARD_VARIANT_FIELDS = [
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
    public function import(string $filename, string $csvData, ?string $productTypeHandle): Product
    {
        $tabularDataReader = $this->read($csvData);
        $titleRecord = array_filter($tabularDataReader->fetchOne());
        if ($titleRecord === [] || count($titleRecord) > 1) {
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

        if ($product->isNewForSite) {
            $variants = $this->normalizeNewProductImport($tabularDataReader, $mapping);
        } else {
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
                $errors = $product->getErrorSummary(false);
                $error = reset($errors);
                throw new \RuntimeException($error ?? 'Failed to save product');
            }
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

        // Order:
        // 1. Variant field mapping (This may change if fields are set per site in the future)
        // 2. Commerce-specific variant fields which are different per site
        // 3. Inventory fields for each inventory location
        // 4. Variant Attribute fields
        $header = array_merge(array_map(static fn($fieldMap) => $fieldMap[1], $mapping['variant']), $sitesHeaders, $inventoryHeaders, $mapping['attribute']);
        $writer->insertOne($header);
        $writer->insertOne([$product->title]);

        foreach ($variants as $variant) {
            $row = $this->normalizeVariantExport($variant, $mapping, $sites);
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
                foreach ($data as $fieldData) {
                    $field = $fieldData['field'];
                    $properties = [
                        $field => $record[$fieldData['index']],
                    ];
                    Typecast::properties($variant::class, $properties);

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
        $duplicateSkus = array_filter($countedSkus, static fn($count): bool => $count > 1);
        if ($duplicateSkus !== []) {
            throw new \RuntimeException('Duplicate SKUs found');
        }

        $foundSkus = $this->findProductVariantSkus($skus);

        // If the product is a new product and the SKU exists already, return an error.
        if ($product->isNewForSite && $foundSkus !== []) {
            throw new \RuntimeException('One or more SKUs already exist');
        }

        // If the SKU already exists for a different product return an error.
        if (array_filter($foundSkus, static fn($key): bool => $key !== $product->id, ARRAY_FILTER_USE_KEY) !== []) {
            throw new \RuntimeException('One or more SKUs already exist on different products');
        }
    }

    /**
     * @throws CsvException
     */
    private function read(string $csvData): \League\Csv\TabularDataReader
    {
        $reader = Reader::createFromString($csvData);
        $reader->setHeaderOffset(0);
        return Statement::create()->process($reader);
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

            $variantId = Variant::find()->product($product)->sku($record[$mapping['variant']['sku']])->one()?->id ?? 0;

            $variants[] = $this->normalizeVariantImport($record, $mapping, $variantId);
        }

        return $variants;
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

        foreach ($mapped as $fieldHandle => $value) {
            $variantElement->{$fieldHandle} = $value;
        }

        foreach ($fields as $fieldHandle => $value) {
            $variantElement->setFieldValue($fieldHandle, $value);
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
            static fn($mapping): bool => ! in_array($mapping, self::STANDARD_PER_SITE_VARIANT_FIELDS, true),
        );
        $remainderSiteProductTypeFields = array_diff(self::STANDARD_VARIANT_FIELDS, array_values($crossSiteProductTypeMap));
        $crossSiteProductTypeMap = [
            ...$crossSiteProductTypeMap,
            ...array_combine($remainderSiteProductTypeFields, $remainderSiteProductTypeFields),
        ];

        $variantSiteMap = array_filter(
            $productTypeMap,
            static fn($mapping): bool => in_array($mapping, self::STANDARD_PER_SITE_VARIANT_FIELDS, true),
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
            $matchedCrossSiteFieldMap = array_filter($crossSiteProductTypeMap, static fn($mapping): bool => $heading === $mapping, ARRAY_FILTER_USE_KEY);
            $matchedVariantFieldMap = array_filter($variantSiteMap, static fn($mapping): bool => str_starts_with($heading, (string) $mapping), ARRAY_FILTER_USE_KEY);

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
            static function(array $inventory) use ($defaultValue): array {
                foreach (array_keys($inventory) as $key) {
                    $inventory[$key] = $defaultValue;
                }

                return $inventory;
            },
            $map,
        );
    }

    /**
     * @param Site[] $sites
     */
    private function normalizeVariantExport(Variant $variant, array $mapping, array $sites): array
    {
        $payload = [];

        // Map Variant field values
        foreach ($mapping['variant'] as [$fieldHandle, $header]) {
            $payload[] = $fieldHandle === 'stock' && $variant->inventoryTracked ? '' : $variant->{$fieldHandle};
        }

        // Map variant values per site
        $mappedSiteValues = $this->valueMapFromMapping($mapping['sites']);
        foreach ($sites as $site) {
            $siteVariant = Variant::find()->id($variant->id)->site($site)->one();
            $siteMapping = $mappedSiteValues[$site->handle];
            foreach ($siteMapping as $key => $value) {
                $siteMapping[$key] = $siteVariant->{$key} ?? '';
            }

            $payload = [
                ...$payload,
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
                $levelMapping = $inventoryMapping[$location];
                $mappedValues = $mappedInventoryValues[$location];
                foreach (array_keys($levelMapping) as $totalKey) {
                    $mappedValues[$totalKey] = $level->{$totalKey};
                }

                $mappedInventoryValues[$location] = $mappedValues;
            }
        }

        foreach ($mappedInventoryValues as $mappedInventoryValue) {
            $payload = [
                ...$payload,
                ...array_values($mappedInventoryValue),
            ];
        }

        // Map Variant Attributes field values
        if ($mapping['fieldHandle']) {
            $handle = $mapping['fieldHandle'];
            $attributes = $variant->{$handle};
            foreach ($attributes ?? [] as $attribute) {
                $payload[] = $attribute['attributeValue'];
            }
        }

        return $payload;
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
            $inventoryLocations = CommercePlugin::getInstance()->getInventoryLocations()->getAllInventoryLocations()->map(static fn($l) => $l->handle);
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
}
