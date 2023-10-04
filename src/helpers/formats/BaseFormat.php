<?php

namespace fostercommerce\variantmanager\helpers\formats;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;

use fostercommerce\variantmanager\helpers\BaseService;

class BaseFormat
{
    /**
     * @var \fostercommerce\variantmanager\helpers\BaseService
     */
    public $service;

    public $controller;

    public $ext = 'txt';

    public $mimetype = 'text/plain';

    public $returnType = 'text/plain';

    public function __construct(BaseService $baseService)
    {
        $this->service = $baseService;
        $this->controller = $baseService->controller;
    }

    public function read($file)
    {
        return null;
    }

    public function import($file)
    {
        $payload = $this->read($file);
        $response = null;

        if ($payload) {
            $payload = $this->normalizeImportPayload($file, $payload);
            $token = Craft::$app->security->generateRandomString(128);

            $response = $this->controller->formatSuccessResponse([
                'products' => $payload,
                'token' => $token,
            ]);

            Craft::$app->cache->set($token, $payload, 3600);
        } else {
            $payload = false;
            $response = $this->controller->formatErrorResponse($payload, 'There was an unknown problem with the CSV file.');
        }

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

        if ($download) {
            $this->controller->setDownloadableAs($product->title . '.' . $this->ext, $this->mimetype);
        }

        if (! $download) {
            $this->controller->returnType = $this->returnType;
        }

        return $this->normalizeExportPayload($product, $variants);
    }

    public function findSKUs(mixed $items)
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

    protected function exportMany($products)
    {
    }

    protected function normalizeImportPayload($file, $payload)
    {
        return null;
    }

    protected function normalizeExportPayload(Product $product, $variants)
    {
        return null;
    }

    protected function getCommercePlugin()
    {
        return Craft::$app->plugins->getPlugin('commerce');
    }
}
