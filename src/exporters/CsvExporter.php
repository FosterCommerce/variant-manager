<?php

namespace fostercommerce\variantmanager\exporters;

use craft\commerce\elements\Product;
use fostercommerce\variantmanager\VariantManager;

class CsvExporter extends Exporter
{
    public string $ext = 'csv';

    public string $mimetype = 'text/csv';

    public function exportProduct(Product $product, array $variants): string
    {
        $mapping = $this->resolveVariantExportMapping($product);

        // TODO CSV generation should use League\Csv
        $results = [
            // Initialize with headers
            implode(',', array_merge(array_map(static fn($fieldMap) => $fieldMap[1], $mapping['variant']), $mapping['option'])),
        ];

        foreach ($variants as $variant) {
            $results[] = $this->normalizeVariantExport($variant, $mapping);
        }

        return implode("\n", $results);
    }

    private function normalizeVariantExport($variant, array $mapping): string
    {
        $payload = [];

        foreach ($mapping['variant'] as [$fieldHandle, $header]) {
            $payload[] = $variant->{$fieldHandle};
        }

        foreach ($variant->variantAttributes ?? [] as $attribute) {
            $payload[] = $attribute['attributeValue'];
        }

        return implode(',', $payload);
    }

    private function resolveVariantExportMapping(Product $product): array
    {
        // TODO what is an optionSignal actually?
        // TODO This should be a config probably?
        $optionSignal = 'Option : ';

        $map = VariantManager::getInstance()->getSettings()->getProductTypeMapping($product->type->handle);

        $variantMap = [];
        foreach (array_keys($map) as $i => $heading) {
            $variantMap[$i] = [$map[$heading], $heading];
        }

        $optionMap = [];
        foreach ($product->variants[0]->variantAttributes ?? [] as $attribute) {
            $optionMap[] = $optionSignal . $attribute['attributeName'];
        }

        return [
            'variant' => $variantMap,
            'option' => $optionMap,
        ];
    }
}
