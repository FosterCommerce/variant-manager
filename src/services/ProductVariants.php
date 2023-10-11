<?php

namespace fostercommerce\variantmanager\services;

use craft\base\Component;
use craft\commerce\elements\Product;

/**
 * ProductVariants
 */
class ProductVariants extends Component
{
    public function getAttributeOptions(Product|int $product, string $fieldHandle): array
    {
        if (is_int($product)) {
            $product = Product::find()->id($product)->one();

            if (! isset($product)) {
                throw new \RuntimeException("Product not found");
            }
        }

        $variants = [];
        foreach ($product->variants as $variant) {
            // Turn the attributes into associative arrays
            $variants[] = array_reduce(
                $variant->$fieldHandle,
                static function ($carry, $item) {
                    $carry[$item['attributeName']] = $item['attributeValue'];
                    return $carry;
                },
                []
            );
        }

        return array_map(static function ($value) {
            // Turn the value into an array if it isn't already one.
            if (! is_array($value)) {
                return [$value];
            }

            // Otherwise make sure the items are unique
            return array_values(array_unique($value));
        }, array_merge_recursive(...$variants));
    }
}
