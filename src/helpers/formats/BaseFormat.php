<?php

namespace fostercommerce\variantmanager\helpers\formats;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;

use craft\web\UploadedFile;
use fostercommerce\variantmanager\exceptions\InvalidSkusException;
use yii\base\Exception;

abstract class BaseFormat
{
    public string $ext = 'txt';

    public string $mimetype = 'text/plain';

    public string $returnType = 'text/plain';

    /**
     * @throws InvalidSkusException
     * @throws Exception
     */
    public function import(\craft\web\UploadedFile $uploadedFile): array
    {
        $payload = $this->normalizeImportPayload($uploadedFile);
        $token = Craft::$app->security->generateRandomString(128);

        $response = [
            'products' => $payload,
            'token' => $token,
        ];

        Craft::$app->cache->set($token, $payload, 3600);

        return $response;
    }

    public function export($product, $variants = null, $download = false)
    {
        if (! ($product instanceof Product)) {
            $product = Product::find()
                ->id((string) $product)
                ->one();
        }

        if ($variants === null) {
            $variants = $product->variants;
        }

        // In future, this should return an error message.

        if (! $product) {
            return null;
        }

        return $this->normalizeExportPayload($product, $variants);
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
    abstract protected function normalizeImportPayload(UploadedFile $uploadedFile);

    abstract protected function normalizeExportPayload(Product $product, $variants);
}
