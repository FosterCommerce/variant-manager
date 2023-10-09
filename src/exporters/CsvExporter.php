<?php

namespace fostercommerce\variantmanager\exporters;

use craft\commerce\elements\Product;

class CsvExporter extends Exporter
{
    public string $ext = 'csv';

    public string $mimetype = 'text/csv';

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

    protected function normalizeExportPayload(Product $product, array $variants): string
    {
        //if ($variants === null || !count($variants)) return null;

        // TODO what is an optionSignal?
        [$mapping, $optionSignal] = $this->resolveVariantExportMapping($product);

        $payload = [
            implode(',', array_merge(array_map(static fn($v) => $v[1], $mapping['variant']), $mapping['option'])),
        ];
        foreach ($variants as $variant) {
            $payload[] = $this->normalizeVariantExport($variant, $mapping);
        }

        return implode("\n", $payload);
    }
}
