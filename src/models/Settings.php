<?php

namespace fostercommerce\variantmanager\models;

use Craft;
use craft\base\Model;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as CommercePlugin;

/**
 * Variant Manager settings
 *
 * @property-read array $availableProductTypes
 */
class Settings extends Model
{
    public string $emptyOptionValue = '';

    public string $optionPrefix = 'Option: ';

    public array $variantFieldMap = [
        '*' => [],
    ];

    public function setAttributes($values, $safeOnly = true): void
    {
        parent::setAttributes($values, $safeOnly);

        // Make sure that the catch-all type always exists
        if ($this->variantFieldMap === []) {
            $this->variantFieldMap = [
                '*' => [],
            ];
        }

        if (! array_key_exists('*', $this->variantFieldMap)) {
            $this->variantFieldMap['*'] = [];
        }
    }

    public function getAvailableProductTypes(): array
    {
        $productTypes = [];
        /** @var CommercePlugin $plugin */
        $plugin = Craft::$app->plugins->getPlugin('commerce');
        foreach (array_keys($this->variantFieldMap) as $productTypeHandle) {
            if ($productTypeHandle === '*') {
                continue;
            }

            $productType = $plugin->productTypes->getProductTypeByHandle($productTypeHandle);
            if ($productType instanceof ProductType) {
                $productTypes[] = $productType;
            }
        }

        return $productTypes;
    }

    public function getProductTypeMapping(?string $productTypeHandle): ?array
    {
        if ($productTypeHandle === null) {
            return $this->variantFieldMap['*'];
        }

        return $this->variantFieldMap[$productTypeHandle] ?? $this->variantFieldMap['*'];
    }
}
