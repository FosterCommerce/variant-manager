<?php

namespace fostercommerce\variantmanager\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\helpers\Product as ProductHelper;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as CommercePlugin;
use craft\errors\ElementNotFoundException;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\Plugin;
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
        'price',
        'width',
        'height',
        'length',
        'weight',
        'stock',
        'hasUnlimitedStock',
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
            $variants = $this->normalizeNewProductImport($product, $tabularDataReader, $mapping);
        } else {
            $variants = $this->normalizeExistingProductImport($product, $tabularDataReader, $mapping);
        }

        $product->setVariants($variants);
        // runValidation needs to be `true` so that updateTitle and updateSku are run against Variants.
        // See: https://github.com/craftcms/commerce/pull/3297
        if (! Craft::$app->elements->saveElement($product, true, true, true)) {
            $errors = $product->getErrorSummary(false);
            $error = reset($errors);
            throw new \RuntimeException($error ?? 'Failed to save product');
        }

        return $product;
    }

    /**
     * @throws CannotInsertRecord
     * @throws CsvException
     */
    public function export(string $productId, array $options = []): array|bool
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
        $mapping = $this->resolveVariantExportMapping($product);

        $writer = Writer::createFromString();

        // Headers include variant fields and attribute options
        $header = array_merge(array_map(static fn($fieldMap) => $fieldMap[1], $mapping['variant']), $mapping['option']);
        $writer->insertOne($header);
        $writer->insertOne([$product->title]);

        foreach ($variants as $variant) {
            $row = $this->normalizeVariantExport($variant, $mapping);
            $writer->insertOne($row);
        }

        return $writer->toString();
    }

    /**
     * @param string[] $items
     */
    protected function findProductVariantSkus(array $items): array
    {
        $found = Variant::find()
            ->sku($items)
            ->all();

        $mapped = [];
        foreach ($found as $variant) {
            if (! array_key_exists($variant->product->id, $mapped)) {
                $mapped[$variant->product->id] = [];
            }

            $mapped[$variant->product->id][] = $variant->sku;
        }

        return $mapped;
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
     * @throws InvalidConfigException
     */
    private function normalizeNewProductImport(Product $product, TabularDataReader $tabularDataReader, array $mapping): array
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

            $variants[] = $this->normalizeVariantImport($product, $record, $mapping, 0);
        }

        return $variants;
    }

    /**
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

            $variants[] = $this->normalizeVariantImport($product, $record, $mapping, $variantId);
        }

        return $variants;
    }

    /**
     * @throws InvalidConfigException
     * @throws \Throwable
     */
    private function normalizeVariantImport(Product $product, array $variant, array $mapping, int $variantId): Variant
    {
        $emptyOptionValue = Plugin::getInstance()->getSettings()->emptyOptionValue;
        // Generate attributes for variant attributes field
        $attributes = [];
        foreach ($mapping['option'] as $field) {
            $value = trim((string) $variant[$field[0]]);
            if ($value === '') {
                $value = $emptyOptionValue;
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

        $mapped['fields'] = $fields;

        if (! array_key_exists('minQty', $mapped)) {
            $mapped['minQty'] = null;
        }

        if (! array_key_exists('maxQty', $mapped)) {
            $mapped['maxQty'] = null;
        }

        $variantElement = ProductHelper::populateProductVariantModel($product, $mapped, $variantId === 0 ? 'new' : $variantId);

        if (($mapped['stock'] ?? '') === '') {
            $variantElement->hasUnlimitedStock = true;
        }

        return $variantElement;
    }

    private function resolveVariantImportMapping(TabularDataReader $tabularDataReader, string $productTypeHandle): array
    {
        $optionPrefix = Plugin::getInstance()->getSettings()->optionPrefix;
        $productTypeMap = Plugin::getInstance()->getSettings()->getProductTypeMapping($productTypeHandle);
        $productType = CommercePlugin::getInstance()->productTypes->getProductTypeByHandle($productTypeHandle);

        if (! $productType instanceof ProductType) {
            throw new \RuntimeException('Invalid product type handle');
        }

        $fieldHandle = FieldHelper::getFirstVariantAttributesField($productType->getVariantFieldLayout())?->handle;

        $variantMap = array_fill_keys(array_values($productTypeMap), null);

        $optionMap = [];
        foreach ($tabularDataReader->getHeader() as $i => $heading) {
            if (array_key_exists(trim($heading), $productTypeMap)) {
                $variantMap[$productTypeMap[trim($heading)]] = $i;
            } elseif (str_starts_with($heading, (string) $optionPrefix)) {
                $optionMap[] = [$i, explode($optionPrefix, $heading)[1]];
            }
        }

        return [
            'variant' => $variantMap,
            'option' => $optionMap,
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

            /** @var CommercePlugin $plugin */
            $plugin = Craft::$app->plugins->getPlugin('commerce');
            $product->typeId = $plugin->getProductTypes()->getProductTypeByHandle($productTypeHandle)->id;
        }

        $product->title = $title;

        return $product;
    }

    private function normalizeVariantExport($variant, array $mapping): array
    {
        $payload = [];

        foreach ($mapping['variant'] as [$fieldHandle, $header]) {
            $payload[] = $fieldHandle === 'stock' && $variant->hasUnlimitedStock ? '' : $variant->{$fieldHandle};
        }

        if ($mapping['fieldHandle']) {
            $handle = $mapping['fieldHandle'];
            $attributes = $variant->{$handle};
            foreach ($attributes ?? [] as $attribute) {
                $payload[] = $attribute['attributeValue'];
            }
        }

        return $payload;
    }

    private function resolveVariantExportMapping(Product $product): array
    {
        $optionPrefix = Plugin::getInstance()->getSettings()->optionPrefix;

        $map = Plugin::getInstance()->getSettings()->getProductTypeMapping($product->type->handle);

        $variantMap = [];
        foreach (array_keys($map) as $i => $heading) {
            $variantMap[$i] = [$map[$heading], $heading];
        }

        $fieldHandle = null;
        $optionMap = [];
        if ($product->variants !== []) {
            $variant = $product->variants[0];
            $fieldHandle = FieldHelper::getFirstVariantAttributesField($variant->getFieldLayout())?->handle;
            if ($fieldHandle !== null) {
                foreach ($variant->{$fieldHandle} ?? [] as $attribute) {
                    $optionMap[] = $optionPrefix . $attribute['attributeName'];
                }
            }
        }

        return [
            'variant' => $variantMap,
            'option' => $optionMap,
            'fieldHandle' => $fieldHandle,
        ];
    }
}
