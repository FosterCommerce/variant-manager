<?php

namespace fostercommerce\variantmanager\exporters;

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use fostercommerce\variantmanager\VariantManager;

abstract class Exporter
{
    public string $ext = 'txt';

    public string $mimetype = 'text/plain';

    public string $returnType = 'text/plain';

    /**
     * @throws \RuntimeException
     */
    public static function create(string $type = 'csv'): self
    {
        return match ($type) {
            'csv' => new CsvExporter(),
            'json' => new JsonExporter(),
            default => throw new \RuntimeException('Invalid export type'),
        };
    }

    public function export(string $productId, array $options = []): array|bool
    {
        /** @var Product|null $product */
        $product = Product::find()->id($productId)->one();

        if (! isset($product)) {
            return false;
        }

        $fieldHandle = $options['fieldHandle'] ?? 'variantAttributes';
        $conditions = $options['conditions'] ?? [];
        $variants = Variant::find()->product($product)->$fieldHandle($conditions)->all();

        return [
            'title' => $product->title,
            'export' => $this->exportProduct($product, $variants),
        ];
    }

    /**
     * @param Variant[] $variants
     */
    abstract public function exportProduct(Product $product, array $variants): mixed;
}
