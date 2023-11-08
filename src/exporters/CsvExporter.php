<?php

namespace fostercommerce\variantmanager\exporters;

use craft\commerce\elements\Product;
use fostercommerce\variantmanager\helpers\FieldHelper;
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

        if ($mapping['fieldHandle']) {
            $handle = $mapping['fieldHandle'];
            $attributes = $variant->{$handle};
            foreach ($attributes ?? [] as $attribute) {
                $payload[] = $attribute['attributeValue'];
            }
        }

        return implode(',', $payload);
    }

    private function resolveVariantExportMapping(Product $product): array
    {
        $optionPrefix = VariantManager::getInstance()->getSettings()->optionPrefix;

        $map = VariantManager::getInstance()->getSettings()->getProductTypeMapping($product->type->handle);

        $variantMap = [];
        foreach (array_keys($map) as $i => $heading) {
            $variantMap[$i] = [$map[$heading], $heading];
        }

        $fieldHandle = null;
        $optionMap = [];
        if ($product->variants !== []) {
            $variant = $product->variants[0];
            $fieldHandle = FieldHelper::getFirstVariantAttributesField($variant->getFieldLayout())->handle;
            foreach ($variant->{$fieldHandle} ?? [] as $attribute) {
                $optionMap[] = $optionPrefix . $attribute['attributeName'];
            }
        }

        return [
            'variant' => $variantMap,
            'option' => $optionMap,
            'fieldHandle' => $fieldHandle,
        ];
    }
}
