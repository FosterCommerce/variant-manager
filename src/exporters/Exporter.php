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
    public static function create(string $type = 'csv'): self
    {
        return match ($type) {
            'csv' => new CsvExporter(),
            'json' => new JsonExporter(),
            default => throw new \RuntimeException('Invalid export type'),
        };
    }

    /**
     * @param Variant[] $variants
     */
    public function export(Product $product, array $variants)
    {
        return $this->normalizeExportPayload($product, $variants);
    }

    /**
     * @param Variant[] $variants
     */
    abstract protected function normalizeExportPayload(Product $product, array $variants);
}
