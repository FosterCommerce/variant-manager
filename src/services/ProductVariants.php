<?php

namespace fostercommerce\variantmanager\services;

use craft\base\Component;
use craft\commerce\elements\Product;

use craft\commerce\elements\Variant;

/**
 * ProductVariants
 */
class ProductVariants extends Component
{
    /**
     * @return Variant[]
     */
    public function getVariantsByOptions(Product $product, $options): array
    {
        $map = [];

        foreach ($product->variants[0]->variantAttributes as $key => $value) {
            $map[$value['attributeName']] = $key;
        }

        $variants = array_filter($product->variants, static function($variant) use ($map, $options): bool {
            foreach ($options as $option) {
                if (! array_key_exists($option[0], $map)) {
                    continue;
                }

                if (! str_contains((string) $variant->variantAttributes[$map[$option[0]]]['attributeValue'], (string) $option[1])) {
                    continue;
                }

                return true;
            }

            return false;
        });

        return array_values($variants);
    }
}
