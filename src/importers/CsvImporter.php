<?php

namespace fostercommerce\variantmanager\importers;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\helpers\Product as ProductHelper;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as CommercePlugin;
use craft\errors\ElementNotFoundException;
use craft\helpers\Db;
use craft\web\UploadedFile;
use fostercommerce\variantmanager\exceptions\InvalidSkusException;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\VariantManager;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;
use League\Csv\Statement;
use League\Csv\TabularDataReader;
use League\Csv\UnableToProcessCsv;
use yii\base\Exception;
use yii\base\InvalidConfigException;

class CsvImporter extends Importer
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
     * @throws InvalidSkusException
     * @throws UnableToProcessCsv
     * @throws ElementNotFoundException
     * @throws \Throwable
     */
    public function import(UploadedFile $uploadedFile): void
    {
        $tabularDataReader = $this->read($uploadedFile);

        $mapping = $this->resolveVariantImportMapping($tabularDataReader);

        $product = $this->resolveProductModel($uploadedFile->baseName);

        if ($product->isNewForSite) {
            $variants = $this->normalizeNewProductImport($product, $tabularDataReader, $mapping);
        } else {
            $variants = $this->normalizeExistingProductImport($product, $tabularDataReader, $mapping);
        }

        $product->setVariants($variants);
        // runValidation needs to be `true` so that updateTitle and updateSku are run against Variants.
        // See: https://github.com/craftcms/commerce/pull/3297
        Craft::$app->elements->saveElement($product, true, false, true);
    }

    /**
     * @throws CsvException
     */
    private function read(UploadedFile $uploadedFile): \League\Csv\TabularDataReader
    {
        $reader = Reader::createFromPath($uploadedFile->tempName, 'r');
        $reader->setHeaderOffset(0);
        return Statement::create()->process($reader);
    }

    /**
     * @throws InvalidSkusException
     * @throws UnableToProcessCsv
     * @throws InvalidConfigException
     */
    private function normalizeNewProductImport(Product $product, TabularDataReader $tabularDataReader, array $mapping): array
    {
        $mappedSKUs = $this->findSKUs(iterator_to_array($tabularDataReader->fetchColumn($mapping['variant']['sku'])));

        // If the SKUs already exist for a new product, throw an error because SKUs should be unique to a product.
        if ((is_countable($mappedSKUs) ? count($mappedSKUs) : 0) > 0) {
            throw new InvalidSkusException($product, $mappedSKUs);
        }

        $variants = [];
        foreach ($tabularDataReader->getRecords() as $record) {
            $record = array_values($record);

            if ($record === []) {
                continue;
            }

            $variants[] = $this->normalizeVariantImport($product, $record, $mapping, false);
        }

        return $variants;
    }

    /**
     * @throws InvalidSkusException
     * @throws UnableToProcessCsv
     * @throws InvalidConfigException
     */
    private function normalizeExistingProductImport(Product $product, TabularDataReader $tabularDataReader, array $mapping): array
    {
        $mappedSKUs = $this->findSKUs(iterator_to_array($tabularDataReader->fetchColumn($mapping['variant']['sku'])));

        // We know in every instance if for some reason there are two product IDs mapped, something is wrong because
        // an SKU should at most be affiliated with a single (one) product.

        // Similarly, if the SKUs aren't associated to current product if it exists then that's problematic too.
        if ((! array_key_exists($product->id, $mappedSKUs) && (is_countable($mappedSKUs) ? count($mappedSKUs) : 0) !== 0) || (is_countable($mappedSKUs) ? count($mappedSKUs) : 0) > 1) {
            throw new InvalidSkusException($product, $mappedSKUs);
        }

        $variants = [];
        foreach ($tabularDataReader->getRecords() as $record) {
            $record = array_values($record);

            if ($record === []) {
                continue;
            }

            $variantId = Variant::find()->product($product)->sku($record[$mapping['variant']['sku']])->one()->id;
            $variants[] = $this->normalizeVariantImport($product, $record, $mapping, $variantId);
        }

        return $variants;
    }

    /**
     * @throws InvalidConfigException
     */
    private function normalizeVariantImport(Product $product, array $variant, array $mapping, bool|int $variantId): Variant
    {
        $emptyOptionValue = VariantManager::getInstance()->getSettings()->emptyOptionValue;
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

        $variantElement = ProductHelper::populateProductVariantModel($product, $mapped, $variantId === false ? 'new' : $variantId);

        if (($mapped['stock'] ?? '') === '') {
            $variantElement->hasUnlimitedStock = true;
        }

        return $variantElement;
    }

    private function resolveVariantImportMapping(TabularDataReader $tabularDataReader): array
    {
        $optionPrefix = VariantManager::getInstance()->getSettings()->optionPrefix;

        // Product mapping is for a future update to allow IDs and metadata to be passed for the product itself (not just variants).

        // TODO we need to make product type an import option
        $productTypeHandle = 'general';

        $productTypeMap = VariantManager::getInstance()->getSettings()->getProductTypeMapping($productTypeHandle);

        $productType = CommercePlugin::getInstance()->productTypes->getProductTypeByHandle($productTypeHandle);

        if (! $productType instanceof ProductType) {
            throw new \RuntimeException('Invalid product type handle');
        }

        $fieldHandle = FieldHelper::getFirstVariantAttributesField($productType->getVariantFieldLayout())->handle;

        $variantMap = array_fill_keys(array_values($productTypeMap), null);

        $optionMap = [];
        foreach ($tabularDataReader->getHeader() as $i => $heading) {
            if (array_key_exists(trim($heading), $productTypeMap)) {
                $variantMap[$productTypeMap[trim($heading)]] = $i;
            } elseif (str_starts_with($heading, $optionPrefix)) {
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
    private function resolveProductModel(string $name): Product
    {
        $productQuery = Product::find()->title(Db::escapeParam($name));

        $existing = $productQuery->one();
        $product = $existing ?? new Product();

        if (! $existing) {
            $product->title = $name;
            $product->isNewForSite = true;

            // TODO I'm pretty sure we want to have some default config for product type IDs
            /** @var CommercePlugin $plugin */
            $plugin = Craft::$app->plugins->getPlugin('commerce');
            $product->typeId = $plugin->getProductTypes()->getAllProductTypeIds()[0];
        }

        return $product;
    }
}
