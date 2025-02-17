<?php

namespace fostercommerce\variantmanager\services;

use craft\base\Component;
use craft\commerce\elements\Product;
use fostercommerce\variantmanager\helpers\FieldHelper;
use yii\base\InvalidConfigException;

class ProductVariants extends Component
{
	/**
	 * @param Product|int $product The product to fetch variant attributes for.
	 * @param array|string|null $only If set, limits the options returned to just the ones in the argument.
	 * @throws InvalidConfigException
	 */
	public function getAttributeOptions(Product|int $product, array|string|null $only = null): array
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

		$fieldHandle = FieldHelper::getFirstVariantAttributesField($product->type->getVariantFieldLayout())?->handle;
		$variants = [];
		foreach ($product->variants as $variant) {
			// Turn the attributes into associative arrays
			$variants[] = array_reduce(
				$variant->{$fieldHandle} ?? [],
				static function (array $carry, array $item) use ($only): array {
					$key = $item['attributeName'];
					if ($only === null || $only === [] || in_array($key, $only, true)) {
						$carry[$key] = $item['attributeValue'];
					}

					return $carry;
				},
				[]
			);
		}

		$merged = array_merge_recursive(...$variants);

		return array_map(static function ($name, $values): array {
			// Turn the value into an array if it isn't already one.
			if (! is_array($values)) {
				$values = [$values];
			}

			// Otherwise make sure the items are unique
			$values = array_values(array_unique($values));

			return [
				'name' => $name,
				'values' => $values,
			];
		}, array_keys($merged), array_values($merged));
	}
}
