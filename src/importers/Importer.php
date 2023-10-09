<?php

namespace fostercommerce\variantmanager\importers;

use Craft;
use craft\commerce\elements\Variant;

use craft\web\UploadedFile;
use fostercommerce\variantmanager\exceptions\InvalidSkusException;
use yii\base\Exception;

abstract class Importer
{
    public string $ext = 'txt';

    public string $mimetype = 'text/plain';

    public string $returnType = 'text/plain';

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
    public function import(UploadedFile $uploadedFile): array
    {
        $payload = $this->normalizeImportPayload($uploadedFile);
        $token = Craft::$app->security->generateRandomString(128);

        $type = $uploadedFile->type;

        Craft::$app->cache->set(
            $token,
            compact('payload', 'type'),
            3600
        );

        return [
            'title' => $payload['title'],
            'isNew' => $payload['isNew'],
            'token' => $token,
        ];
    }

    public function findSKUs(mixed $items): array
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

    /**
     * @throws InvalidSkusException
     */
    abstract protected function normalizeImportPayload(UploadedFile $uploadedFile): array;
}
