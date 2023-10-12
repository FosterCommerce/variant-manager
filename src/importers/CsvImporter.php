<?php

namespace fostercommerce\variantmanager\importers;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as CommercePlugin;
use craft\errors\ElementNotFoundException;
use craft\helpers\Db;
use craft\web\UploadedFile;
use fostercommerce\variantmanager\exceptions\InvalidSkusException;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;
use League\Csv\Statement;
use League\Csv\TabularDataReader;
use League\Csv\UnableToProcessCsv;
use yii\base\Exception;
use yii\base\InvalidConfigException;

class CsvImporter extends Importer
{
    // Read as "From" => "To"

    private array $variantHeadings = [
        'SKU' => 'sku',
        'Stock' => 'stock',
        'Price' => 'price',
        'Height' => 'height',
        'Width' => 'width',
        'Length' => 'length',
        'Weight' => 'weight',
        // TODO : Hard-coding these in for now, we should pull these from the plugins config file
        'PART_NO' => 'mpn',
        'CrossRef_Num' => 'crossReferenceNumber',
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
     * @return Variant[]
     */
    private function normalizeNewProductImport(Product $product, TabularDataReader $tabularDataReader, array $mapping): array
    {
        $mappedSKUs = $this->findSKUs(iterator_to_array($tabularDataReader->fetchColumn($mapping['variant']['sku'])));

        // If the SKUs already exist for a new product, throw an error because SKUs should be unique to a product.
        if ((is_countable($mappedSKUs) ? count($mappedSKUs) : 0) > 0) {
            throw new InvalidSkusException($product, $mappedSKUs);
        }

        $variants = [];
        foreach ($tabularDataReader->getRecords() as $i => $record) {
            $record = array_values($record);

            if ($record === []) {
                continue;
            }

            $variants["new{$i}"] = $this->normalizeVariantImport($record, $mapping);
        }

        return $variants;
    }

    /**
     * @throws InvalidSkusException
     * @throws UnableToProcessCsv
     * @return Variant[]
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
        foreach ($tabularDataReader->getRecords() as $i => $record) {
            $record = array_values($record);

            if ($record === []) {
                continue;
            }

            // Commerce expects the key for an existing variant to be an integer, not a string.
            // So consequently, we're responsible for casting it to the correct type.

            $key = (array_key_exists($record[$mapping['variant']['sku']], $mappedSKUs[$product->id])) ? (int) $product->id : "new{$i}";

            $variants[$key] = $this->normalizeVariantImport($record, $mapping);
        }

        return $variants;
    }

    private function normalizeVariantImport($variant, array $mapping): Variant
    {
        $attributes = [];
        foreach ($mapping['option'] as $field) {
            $attributes[] = [
                'attributeName' => $field[1],
                'attributeValue' => trim((string) $variant[$field[0]]),
            ];
        }

        $variant = [
            'price' => $this->stripCurrency($variant[$mapping['variant']['price']] ?? 0),
            'sku' => $variant[$mapping['variant']['sku']],
            'stock' => $variant[$mapping['variant']['stock']],
            'height' => $variant[$mapping['variant']['height']] ?? 0,
            'width' => $variant[$mapping['variant']['width']] ?? 0,
            'length' => $variant[$mapping['variant']['length']] ?? 0,
            'weight' => $variant[$mapping['variant']['weight']] ?? 0,
            'minQty' => null,
            'maxQty' => null,
            'fields' => [
                'variantAttributes' => $attributes,
                // TODO : Hard-coding these in for now, we should pull these from the plugins config file
                'mpn' => $variant[$mapping['variant']['mpn']],
                'crossReferenceNumber' => $variant[$mapping['variant']['crossReferenceNumber']],
            ],
        ];

        if ($variant['stock'] === '') {
            $variant['hasUnlimitedStock'] = true;
        }

        return new Variant($variant);
    }

    private function stripCurrency($amount)
    {
        $amount = str_replace(['?', ','], '', mb_convert_encoding((string) $amount, 'UTF-8', 'UTF-8'));

        if (is_numeric($amount)) {
            return $amount;
        }

        $localeCode = 'en_US';
        $currencyCode = 'USD';

        $numberFormatter = new \NumberFormatter($localeCode, \NumberFormatter::DECIMAL);

        return $numberFormatter->parseCurrency(trim($amount), $currencyCode);
    }

    private function resolveVariantImportMapping(TabularDataReader $tabularDataReader): array
    {
        // TODO this should be a config probably. See the CSV importer implementation.
        $optionSignal = 'Option : ';

        // Product mapping is for a future update to allow IDs and metadata to be passed for the product itself (not just variants).

        $variantMap = array_fill_keys(array_values($this->variantHeadings), -1);
        $optionMap = [];
        foreach ($tabularDataReader->getHeader() as $i => $heading) {
            if (array_key_exists(trim($heading), $this->variantHeadings)) {
                $variantMap[$this->variantHeadings[trim($heading)]] = $i;
            } elseif (str_starts_with($heading, $optionSignal)) {
                $optionMap[] = [$i, explode($optionSignal, $heading)[1]];
            }
        }

        return [
            'variant' => $variantMap,
            'option' => $optionMap,
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
