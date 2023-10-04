<?php

namespace fostercommerce\variantmanager\services;

use Craft;
use craft\base\PluginInterface;
use craft\commerce\elements\Product;
use fostercommerce\variantmanager\helpers\BaseService;

use GuzzleHttp;

/**
 * ProductVariants
 */
class ProductVariants extends BaseService
{
    public function init(): void
    {
    }

    public function getVariants($product)
    {
        if (! ($product instanceof Product)) {
            $product = $this->getProduct($product);
        }

        return $product->variants;
    }

    public function getVariantsByOptions($product, $options): array
    {
        if (! ($product instanceof Product)) {
            $product = $this->getProduct($product);
        }

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

    public function getProduct($product)
    {
        return Product::find()
            ->id((string) $product)
            ->one();
    }

    protected function normalizeVariant($variant)
    {
    }

    /**
     * createClient
     *
     * Creates a Guzzle Client.
     */
    protected function createClient(): GuzzleHttp\Client
    {
        return new GuzzleHttp\Client();
    }

    protected function getCommercePlugin(): PluginInterface
    {
        return Craft::$app->plugins->getPlugin('commerce');
    }
}
