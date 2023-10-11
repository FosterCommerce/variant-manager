<?php

namespace fostercommerce\variantmanager\exporters;

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;

abstract class Exporter
{
    public string $ext = 'txt';

    public string $mimetype = 'text/plain';

    public string $returnType = 'text/plain';

    /**
     * @throws \RuntimeException
     */
    public static function create(ExportType $exportType): self
    {
        return match ($exportType) {
            ExportType::Csv => new CsvExporter(),
            ExportType::Json => new JsonExporter(),
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
        $variants = Variant::find()->product($product)->{$fieldHandle}($conditions)->all();

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
