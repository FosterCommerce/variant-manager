<?php

namespace fostercommerce\variantmanager\exporters;

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use fostercommerce\variantmanager\fields\VariantAttributesField;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\VariantManager;

class JsonExporter extends Exporter
{
    public string $ext = 'json';

    public string $mimetype = 'application/json';

    public string $returnType = 'application/json';

    public function exportProduct(Product $product, array $variants): array
    {
        if (empty($product->variants)) {
            return [];
        }

        $results = [];

        $field = null;
        foreach ($variants as $variant) {
            if (! $field instanceof VariantAttributesField) {
                $field = FieldHelper::getFirstVariantAttributesField($variant->getFieldLayout());
            }

            $results[] = $this->normalizeVariant($variant, $field->handle);
        }

        return $results;
    }

    private function normalizeVariant(Variant $variant, string $variantFieldHandle): array
    {
        $result = [];

        $fields = array_values(VariantManager::getInstance()->getSettings()->getProductTypeMapping($variant->product->type->handle));

        foreach ($fields as $field) {
            $result[$field] = $variant->{$field};
        }

        if (in_array('stock', $fields, true) && $variant->hasUnlimitedStock) {
            $result['stock'] = '';
        }

        $result['attributes'] = [];

        if ($variant->{$variantFieldHandle}) {
            foreach ($variant->{$variantFieldHandle} as $attribute) {
                $result['attributes'][] = [
                    'name' => $attribute['attributeName'],
                    'value' => $attribute['attributeValue'],
                ];
            }
        }

        return $result;
    }
}
