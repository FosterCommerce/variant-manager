<?php

namespace fostercommerce\variantmanager\exporters;

use craft\commerce\elements\Product;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\VariantManager;
use League\Csv\CannotInsertRecord;
use League\Csv\Exception;
use League\Csv\Writer;

class CsvExporter extends Exporter
{
    public string $ext = 'csv';

    public string $mimetype = 'text/csv';

    /**
     * @throws CannotInsertRecord
     * @throws Exception
     */
    public function exportProduct(Product $product, array $variants): string
    {
        $mapping = $this->resolveVariantExportMapping($product);

        $writer = Writer::createFromString();

        // Headers include variant fields and attribute options
        $header = array_merge(array_map(static fn($fieldMap) => $fieldMap[1], $mapping['variant']), $mapping['option']);
        $writer->insertOne($header);
        $writer->insertOne([$product->title]);

        foreach ($variants as $variant) {
            $row = $this->normalizeVariantExport($variant, $mapping);
            $writer->insertOne($row);
        }

        return $writer->toString();
    }

    private function normalizeVariantExport($variant, array $mapping): array
    {
        $payload = [];

        foreach ($mapping['variant'] as [$fieldHandle, $header]) {
            $payload[] = $fieldHandle === 'stock' && $variant->hasUnlimitedStock ? '' : $variant->{$fieldHandle};
        }

        if ($mapping['fieldHandle']) {
            $handle = $mapping['fieldHandle'];
            $attributes = $variant->{$handle};
            foreach ($attributes ?? [] as $attribute) {
                $payload[] = $attribute['attributeValue'];
            }
        }

        return $payload;
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
