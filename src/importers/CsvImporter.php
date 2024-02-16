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
use DateTime;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\records\Activity;
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
     * @throws UnableToProcessCsv
     * @throws ElementNotFoundException
     * @throws \Throwable
     */
    public function import(UploadedFile $uploadedFile, ?string $productTypeHandle): array
    {
        $currentUser = Craft::$app->getUser()->identity;
        $activity = new Activity([
            'userId' => $currentUser->id,
            'username' => $currentUser->username,
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
        ]);

        $tabularDataReader = $this->read($uploadedFile);
        $titleRecord = array_filter($tabularDataReader->fetchOne());
        if ($titleRecord === [] || count($titleRecord) > 1) {
            throw new \RuntimeException('Invalid product title');
        }

        $productId = explode('__', $uploadedFile->baseName)[0] ?? null;
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
            $activity->message = "imported new product <a class=\"go\" href=\"{$product->getCpEditUrl()}\">{$product->title}</a> into {$product->type->name}";
            $variants = $this->normalizeNewProductImport($product, $tabularDataReader, $mapping);
        } else {
            $activity->message = "imported existing product <a class=\"go\" href=\"{$product->getCpEditUrl()}\">{$product->title}</a> into {$product->type->name}";
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

        $activity->save();

        Activity::deleteAll([
            'id' => Activity::find()->select(['id'])->orderBy([
                'dateCreated' => SORT_DESC,
            ])->offset(Activity::ACTIVITY_LIMIT)->column(),
        ]);

        return [
            'title' => $product->title,
            'url' => $product->getCpEditUrl(),
        ];
    }

    /**
     * @throws UnableToProcessCsv
     */
    private function validateSkus(Product $product, array $mapping, TabularDataReader $tabularDataReader): void
    {
        // Exit early if there are duplicate SKUs
        $skuColumn = $mapping['variant']['sku'];
        $skus = iterator_to_array($tabularDataReader->fetchColumn($skuColumn));

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
    private function read(UploadedFile $uploadedFile): \League\Csv\TabularDataReader
    {
        $reader = Reader::createFromPath($uploadedFile->tempName, 'r');
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

        $variantElement = ProductHelper::populateProductVariantModel($product, $mapped, $variantId === 0 ? 'new' : $variantId);

        if (($mapped['stock'] ?? '') === '') {
            $variantElement->hasUnlimitedStock = true;
        }

        return $variantElement;
    }

    private function resolveVariantImportMapping(TabularDataReader $tabularDataReader, string $productTypeHandle): array
    {
        $optionPrefix = VariantManager::getInstance()->getSettings()->optionPrefix;
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
}
