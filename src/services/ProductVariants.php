<?php

namespace fostercommerce\variantmanager\services;

use craft\base\Component;
use craft\commerce\elements\Product;

/**
 * ProductVariants
 */
class ProductVariants extends Component
{
    /**
     * @param Product|int $product The product to fetch variant attributes for.
     * @param string $fieldHandle The field handle used for the variant attributes field.
     * @param array|string|null $only If set, limits the options returned to just the ones in the argument.
     */
    public function getAttributeOptions(Product|int $product, string $fieldHandle, array|string|null $only = null): array
    {
        if (is_int($product)) {
            $product = Product::find()->id($product)->one();

            if (! isset($product)) {
                throw new \RuntimeException('Product not found');
            }
        }

        if (is_string($only)) {
            $only = [$only];
        }

        $variants = [];
        foreach ($product->variants as $variant) {
            // Turn the attributes into associative arrays
            $variants[] = array_reduce(
                $variant->{$fieldHandle} ?? [],
                static function(array $carry, array $item) use ($only): array {
                    $key = $item['attributeName'];
                    if ($only === null || $only === [] || in_array($key, $only, true)) {
                        $carry[$key] = $item['attributeValue'];
                    }

                    return $carry;
                },
                []
            );
        }

        return array_map(static function($value): array {
            // Turn the value into an array if it isn't already one.
            if (! is_array($value)) {
                return [$value];
            }

            // Otherwise make sure the items are unique
            return array_values(array_unique($value));
        }, array_merge_recursive(...$variants));
    }
}
