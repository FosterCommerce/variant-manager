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

        foreach ($variants as $variant) {
            $payload[] = $this->normalizeVariant($variant);
        }

        return $payload;
    }

    private function normalizeVariant($variant): array
    {
        $payload = [];

        foreach ($this->variantHeadings as $key => $value) {
            $payload[$value] = $variant->{$key};
        }

        $stockMapping = $this->variantHeadings['stock'];
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
