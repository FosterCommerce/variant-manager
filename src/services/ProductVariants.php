<?php

namespace fostercommerce\variantmanager\services;

use craft\base\Component;
use craft\commerce\elements\Product;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\elements\VariantAttributeOption;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\Plugin;
use yii\base\InvalidConfigException;

class ProductVariants extends Component
{
	/**
	 * Get each attribute name and the values a product's variants use.
	 *
	 * @param Product|int $product The product to fetch variant attributes for.
	 * @param array|string|null $only If set, limits the options returned to just the ones in the argument.
	 * @return array<int, array{name: string, values: list<string>}>
	 * @throws InvalidConfigException
	 */
	public function getAttributeOptions(Product|int $product, array|string|null $only = null): array
	{
		$attributeOptions = [];

		foreach ($this->valuesByName($product, $only) as $name => $values) {
			$attributeOptions[] = [
				'name' => $name,
				'values' => $values,
			];
		}

		return $attributeOptions;
	}

	/**
	 * Get each attribute name and its values, with the matching registry attribute and options.
	 *
	 * @param Product|int $product The product to fetch variant attributes for.
	 * @param array|string|null $only If set, limits the options returned to just the ones in the argument.
	 * @return array<int, array{name: string, values: list<string>, attribute: ?VariantAttribute, options: array<string, VariantAttributeOption>}>
	 * @throws InvalidConfigException
	 */
	public function getAttributeRegistry(Product|int $product, array|string|null $only = null): array
	{
		$valuesByName = $this->valuesByName($product, $only);
		$registry = Plugin::getInstance()->getVariantAttributes()->getRegistry($valuesByName);

		$attributeOptions = [];

		foreach ($valuesByName as $name => $values) {
			$attributeOptions[] = [
				'name' => $name,
				'values' => $values,
				'attribute' => $registry[$name]['attribute'] ?? null,
				'options' => $registry[$name]['options'] ?? [],
			];
		}

		return $attributeOptions;
	}

	/**
	 * @return array<string, list<string>>
	 * @throws InvalidConfigException
	 */
	private function valuesByName(Product|int $product, array|string|null $only): array
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
				static function (array $carry, array $pair) use ($only): array {
					$key = $pair['attributeName'];
					if ($only === null || $only === [] || in_array($key, $only, true)) {
						$carry[$key] = $pair['attributeValue'];
					}

					return $carry;
				},
				[]
			);
		}

		$merged = array_merge_recursive(...$variants);

		$valuesByName = [];
		foreach ($merged as $name => $values) {
			// Wrap a lone value, since array_merge_recursive only nests on a repeated name
			$valuesByName[$name] = array_values(array_unique(is_array($values) ? $values : [$values]));
		}

		return $valuesByName;
	}
}
