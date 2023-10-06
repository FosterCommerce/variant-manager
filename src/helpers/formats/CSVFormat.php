<?php

namespace fostercommerce\variantmanager\helpers\formats;

use craft\commerce\elements\Product;
use craft\web\UploadedFile;
use fostercommerce\variantmanager\exceptions\InvalidSkusException;
use fostercommerce\variantmanager\VariantManager;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;
use League\Csv\Statement;
use League\Csv\TabularDataReader;
use League\Csv\UnableToProcessCsv;

class CSVFormat extends BaseFormat
{
    public string $ext = 'csv';

    public string $mimetype = 'text/csv';

    public string $returnType = 'text/plain';

    // Read as "From" => "To"

    public $variantHeadings = [
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
     */
    public function read(UploadedFile $uploadedFile): \League\Csv\TabularDataReader
    {
        $reader = Reader::createFromPath($uploadedFile->tempName, 'r');
        $reader->setHeaderOffset(0);
        return Statement::create()->process($reader);
    }

    /**
     * @throws InvalidSkusException
     */
    public function normalizeNewProductImport(Product $product, $payload, array $mapping): array
    {
        $mappedSKUs = $this->findSKUs(iterator_to_array($payload->fetchColumn($mapping['variant']['sku'])));

        // If the SKUs already exist for a new product, throw an error because SKUs should be unique to a product.

        if ((is_countable($mappedSKUs) ? count($mappedSKUs) : 0) > 0) {
            throw new InvalidSkusException($product, $mappedSKUs);
        }

        $variants = [];
        foreach ($payload->getRecords() as $i => $record) {
            $record = array_values($record);

            if ($record === []) {
                continue;
            }

            $key = sprintf('new%s', $i);

            $variants[$key] = $this->normalizeVariantImport($record, $mapping);
        }

        return [$product, $variants];
    }

    /**
     * @throws InvalidSkusException
     */
    public function normalizeExistingProductImport($product, $payload, array $mapping): array
    {
        $mappedSKUs = $this->findSKUs(iterator_to_array($payload->fetchColumn($mapping['variant']['sku'])));

        // We know in every instance if for some reason there are two product IDs mapped, something is wrong because
        // an SKU should at most be affiliated with a single (one) product.

        // Similarly, if the SKUs aren't associated to current product if it exists then that's problematic too.

        if ((! array_key_exists($product->id, $mappedSKUs) && (is_countable($mappedSKUs) ? count($mappedSKUs) : 0) !== 0) || (is_countable($mappedSKUs) ? count($mappedSKUs) : 0) > 1) {
            throw new InvalidSkusException($product, $mappedSKUs);
        }

        $variants = [];
        foreach ($payload->getRecords() as $i => $record) {
            $record = array_values($record);

            if ($record === []) {
                continue;
            }

            // Commerce expects the key for an existing variant to be an integer, not a string.
            // So consequently, we're responsible for casting it to the correct type.

            $key = (array_key_exists($record[$mapping['variant']['sku']], $mappedSKUs[$product->id])) ? (int) $product->id : sprintf('new%s', $i);

            $variants[$key] = $this->normalizeVariantImport($record, $mapping);
        }

        return [$product, $variants];
    }

    public function normalizeVariantImport($variant, array $mapping = null)
    {
        if ($mapping === null || $mapping === []) {
            $mapping = $this->resolveVariantImportMapping($variant)[0];
        }

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

        return $variant;
    }

    public function normalizeVariantExport($variant, array $mapping = null): string
    {
        if ($mapping === null || $mapping === []) {
            $mapping = $this->resolveVariantExportMapping($variant)[0];
        }

        $payload = [];

        foreach ($mapping['variant'] as [$from, $to]) {
            $payload[] = $variant->{$from};
        }

        foreach ($variant->variantAttributes as $attribute) {
            $payload[] = $attribute['attributeValue'];
        }

        return implode(',', $payload);
    }

    public function resolveVariantExportMapping(&$product, $optionSignal = null): array
    {
        $optionSignal ??= 'Option : ';

        $variantMap = [];
        foreach (array_keys($this->variantHeadings) as $i => $heading) {
            $variantMap[$i] = [$this->variantHeadings[$heading], $heading];
        }

        $optionMap = [];
        foreach ($product->variants[0]->variantAttributes as $attribute) {
            $optionMap[] = $optionSignal . $attribute['attributeName'];
        }

        return [
            [
                'variant' => $variantMap,
                'option' => $optionMap,
            ],
            $optionSignal,
        ];
    }

    public function resolveProductModelFromFile($file): Product
    {
        $name = $file->baseName;

        return $this->resolveProductModel($name);
    }

    public function resolveProductModelFromCache(array $payload): Product
    {
        $name = $payload['title'];

        return $this->resolveProductModel($name);
    }

    public function stripCurrency($amount)
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

    /**
     * @throws UnableToProcessCsv
     * @throws CsvException
     * @throws InvalidSkusException
     */
    protected function normalizeImportPayload(UploadedFile $uploadedFile): array
    {
        $tabularDataReader = $this->read($uploadedFile);

        [$mapping] = $this->resolveVariantImportMapping($tabularDataReader);

        $product = $this->resolveProductModelFromFile($uploadedFile);

        $this->findSKUs(iterator_to_array($tabularDataReader->fetchColumn($mapping['variant']['sku'])));

        if ($product->isNewForSite) {
            [$product, $variants] = $this->normalizeNewProductImport($product, $tabularDataReader, $mapping);
        } else {
            [$product, $variants] = $this->normalizeExistingProductImport($product, $tabularDataReader, $mapping);
        }

        return [
            [
                'title' => $product->title,
                'typeId' => $product->typeId,
                'id' => $product->id,
                'isNew' => $product->isNewForSite,
                'variants' => $variants,
            ],
        ];
    }

    protected function normalizeExportPayload(Product $product, $variants): string
    {
        //if ($variants === null || !count($variants)) return null;

        [$mapping, $optionSignal] = $this->resolveVariantExportMapping($product);

        $payload = [
            implode(',', array_merge(array_map(static fn($v) => $v[1], $mapping['variant']), $mapping['option'])),
        ];
        foreach ($variants as $variant) {
            $payload[] = $this->normalizeVariantExport($variant, $mapping);
        }

        return implode("\n", $payload);
    }

    private function resolveVariantImportMapping(TabularDataReader $tabularDataReader): array
    {
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
            [
                'variant' => $variantMap,
                'option' => $optionMap,
            ],
        ];
    }

    private function resolveProductModel(string $name): Product
    {
        $productQuery = Product::find()
            ->title(\craft\helpers\Db::escapeParam($name));

        $existing = $productQuery->one();
        $product = $existing ?? new Product();

        if (! $existing) {
            $product->title = $name;
            $product->typeId = VariantManager::getInstance()->commercePlugin->getProductTypes()->getAllProductTypeIds()[0];
            $product->isNewForSite = true;
        }

        return $product;
    }
}
