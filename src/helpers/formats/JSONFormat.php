<?php

namespace fostercommerce\variantmanager\helpers\formats;

use craft\commerce\elements\Product;

class JSONFormat extends BaseFormat
{
    public $ext = 'json';

    public $mimetype = 'application/json';

    public $returnType = 'application/json';

    public $variantHeadings = [
        'id' => 'id',
        'title' => 'title',
        'sku' => 'sku',
        'stock' => 'stock',
        'minQty' => 'minQty',
        'maxQty' => 'maxQty',
        'onSale' => 'onSale',
        'price' => 'price',
        'priceAsCurrency' => 'priceAsCurrency',
        'salePrice' => 'salePrice',
        'salePriceAsCurrency' => 'salePriceAsCurrency',
        'height' => 'height',
        'width' => 'width',
        'length' => 'length',
        'weight' => 'weight',
        'isAvailable' => 'isAvailable',
        // TODO : Hard-coding these in for now, we should pull these from the plugins config file
        'mpn' => 'mpn',
        'crossReferenceNumber' => 'crossReferenceNumber',
    ];

    public function read($file): never
    {
        throw new \RuntimeException('Importing using a JSON format has not been implemented yet.');
    }

    public function normalizeVariants($variants, array $mapping = null): array
    {
        return array_map(fn($variant) => $this->normalizeVariant($variant, $mapping), $variants);
    }

    public function normalizeVariant($variant, array $mapping = null)
    {
        if ($mapping === null || $mapping === []) {
            $mapping = $this->resolveVariantExportMapping($variant)[0];
        }

        $payload = [];

        foreach ($mapping['variant'] as [$from, $to]) {
            $payload[$to] = $variant->{$from};
        }

        if ($this->findMapping($mapping['variant'], 'stock') && $variant->hasUnlimitedStock) {
            $payload[$this->findMapping($mapping['variant'], 'stock')] = '';
        }

        $payload['attributes'] = [];

        if ($variant->variantAttributes) {
            foreach ($variant->variantAttributes as $attribute) {
                $payload['attributes'][] = [

                    'name' => $attribute['attributeName'],
                    'value' => $attribute['attributeValue'],

                ];
            }
        }

        return $payload;
    }

    public function resolveVariantExportMapping(&$variant): array
    {
        $variantMap = [];
        foreach (array_keys($this->variantHeadings) as $i => $heading) {
            $variantMap[$i] = [$this->variantHeadings[$heading], $heading];
        }

        return [
            [
                'variant' => $variantMap,
            ],
        ];
    }

    public function findMapping($mapping, $predicate)
    {
        foreach ($mapping as [$from, $to]) {
            if ($from === $predicate) {
                return $to;
            }
        }

        return null;
    }

    protected function normalizeExportPayload(Product $product, $variants)
    {
        $payload = [];

        if (! $variants || (is_countable($variants) ? count($variants) : 0) === 0) {
            return $payload;
        }

        [$mapping] = $this->resolveVariantExportMapping($variants[0]);

        foreach ($variants as $variant) {
            $payload[] = $this->normalizeVariant($variant, $mapping);
        }

        return $payload;
    }
}
