<?php

namespace fostercommerce\variantmanager\exporters;

use craft\commerce\elements\Product;

class JsonExporter extends Exporter
{
    public string $ext = 'json';

    public string $mimetype = 'application/json';

    public string $returnType = 'application/json';

    private array $variantHeadings = [
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

    public function exportProduct(Product $product, $variants): array
    {
        if (empty($product->variants)) {
            return [];
        }

        $payload = [];

        [$mapping] = $this->resolveVariantExportMapping();

        foreach ($variants as $variant) {
            $payload[] = $this->normalizeVariant($variant, $mapping);
        }

        return $payload;
    }

    private function resolveVariantExportMapping(): array
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

    private function findStockMapping($mapping)
    {
        foreach ($mapping as [$from, $to]) {
            if ($from === 'stock') {
                return $to;
            }
        }

        return null;
    }

    private function normalizeVariant($variant, array $mapping = null): array
    {
        $payload = [];

        foreach ($mapping['variant'] as [$from, $to]) {
            $payload[$to] = $variant->{$from};
        }

        $stockMapping = $this->findStockMapping($mapping['variant']);
        if ($stockMapping && $variant->hasUnlimitedStock) {
            $payload[$stockMapping] = '';
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
}
