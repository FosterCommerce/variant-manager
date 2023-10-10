<?php

namespace fostercommerce\variantmanager\importers;

use craft\commerce\elements\Variant;

use craft\web\UploadedFile;
use fostercommerce\variantmanager\exceptions\InvalidSkusException;
use yii\base\Exception;

abstract class Importer
{
    /**
     * @throws \RuntimeException
     */
    public static function create(string $type): self
    {
        return match ($type) {
            'text/csv' => new CsvImporter(),
            'application/json' => new JsonImporter(),
            default => throw new \RuntimeException('Unsupported input file type'),
        };
    }

    /**
     * @throws InvalidSkusException
     * @throws Exception
     */
    abstract public function import(UploadedFile $uploadedFile): void;

    protected function findSKUs(mixed $items): array
    {
        $found = Variant::find()
            ->sku($items)
            ->all();

        $mapped = [];
        foreach ($found as $variant) {
            if (! array_key_exists($variant->product->id, $mapped)) {
                $mapped[$variant->product->id] = [];
            }

            $mapped[$variant->product->id][] = $variant->sku;
        }

        return $mapped;
    }
}
