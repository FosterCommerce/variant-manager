<?php

namespace fostercommerce\variantmanager\services;

use craft\base\Component;
use craft\commerce\elements\Product;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\helpers\FieldHelper;
use fostercommerce\variantmanager\Plugin;
use yii\base\InvalidConfigException;

class ProductVariants extends Component
{
	/**
	 * Get each attribute name and the values a product's variants use.
	 *
	 * @param Product|int $product The product to fetch variant attributes for.
	 * @param list<string>|string|null $only If set, limits the options returned to just the ones in the argument.
	 * @return array<int, array{name: string, values: list<string>}>
	 * @throws InvalidConfigException
	 */
	public function getAttributeOptions(Product|int $product, array|string|null $only = null): array
	{
		$attributeOptions = [];

		foreach ($this->valuesByName($product, $only) as $name => $values) {
			$attributeOptions[] = [
				'name' => (string) $name,
				'values' => $values,
			];
		}

		return $attributeOptions;
	}

	/**
	 * Get each attribute name and its values, with the matching registry attribute and options.
	 *
	 * @param Product|int $product The product to fetch variant attributes for.
	 * @param list<string>|string|null $only If set, limits the options returned to just the ones in the argument.
	 * @return array<int, array{name: string, values: list<string>, attribute: ?VariantAttribute, options: array<string, VariantAttribute>}>
	 * @throws InvalidConfigException
	 */
	public function getAttributeRegistry(Product|int $product, array|string|null $only = null): array
	{
		$valuesByName = $this->valuesByName($product, $only);
		$registry = Plugin::getInstance()->getVariantAttributes()->getRegistry($valuesByName);

		$attributeOptions = [];

		foreach ($valuesByName as $name => $values) {
			$attributeOptions[] = [
				'name' => (string) $name,
				'values' => $values,
				'attribute' => $registry[$name]['attribute'] ?? null,
				'options' => $registry[$name]['options'] ?? [],
			];
		}

		return $attributeOptions;
	}

	/**
	 * @param list<string>|string|null $only
	 * @return array<string, list<string>>
	 * @throws InvalidConfigException
	 */
	private function valuesByName(Product|int $product, array|string|null $only): array
	{
		if (is_int($product)) {
			$product = Product::find()->id($product)->one();

			if (! $product instanceof Product) {
				throw new \RuntimeException('Product not found');
			}
		}

		if (is_string($only)) {
			$only = [$only];
		}

		$fieldHandle = FieldHelper::getFirstVariantAttributesField($product->type->getVariantFieldLayout())?->handle;
		$valuesByName = [];

		foreach ($product->variants as $variant) {
			$storedPairs = $variant->{$fieldHandle} ?? [];

			// An unparseable JSON field value is the raw string
			if (! is_array($storedPairs)) {
				continue;
			}

			foreach ($storedPairs as $storedPair) {
				if (! is_string($storedPair['attributeName'] ?? null)) {
					continue;
				}

				if (! is_string($storedPair['attributeValue'] ?? null)) {
					continue;
				}

				if ($only !== null && $only !== [] && ! in_array($storedPair['attributeName'], $only, true)) {
					continue;
				}

				$valuesByName[$storedPair['attributeName']][] = $storedPair['attributeValue'];
			}
		}

		// Key by name without merging arrays, so a numeric name such as "12" keeps its values
		return array_map(static fn (array $values): array => array_values(array_unique($values)), $valuesByName);
	}
}
