<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;

use craft\commerce\elements\Product;
use craft\web\UploadedFile;

use fostercommerce\variantmanager\exceptions\BaseVariantManagerException;
use fostercommerce\variantmanager\helpers\BaseController;
use fostercommerce\variantmanager\helpers\formats\CSVFormat;

use fostercommerce\variantmanager\helpers\formats\JSONFormat;
use fostercommerce\variantmanager\services\ProductVariants;

/**
 * @property-read ProductVariants $service
 */
class ProductVariantsController extends BaseController
{
    public $service_name = 'productVariants';

    public function actionUpload(): void
    {
        $this->requirePostRequest();

        try {
            $this->respond($this->handleUpload());
        } catch (BaseVariantManagerException $baseVariantManagerException) {
            $this->setStatus(500);

            $this->respond($this->formatErrorResponse(null, $baseVariantManagerException->getMessage()));
        }
    }

    public function actionApplyUpload(): void
    {
        $this->requirePostRequest();

        $this->respond($this->handleApplyUpload());
    }

    public function actionExport(): void
    {
        $payload = $this->handleExport();
        $this->respond($payload);
    }

    public function throwInvalidSKUsError($product, array $items): void
    {
        $message = "Sorry, but the following SKUs already exist for the given product IDs:\n\n";

        if ($product && array_key_exists($product->id, $items)) {
            unset($items[$product->id]);
        }

        foreach ($items as $id => $skus) {
            $normalized = implode(', ', $skus);

            $message .= sprintf('<strong>%s</strong>: %s%s', $id, $normalized, PHP_EOL);
        }

        throw new BaseVariantManagerException(nl2br($message));
    }

    public function handleExport()
    {
        $id = $this->parameter('id');
        $format = $this->parameter('format') ?? 'json';
        $download = filter_var($this->parameter('download'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        [$product, $variants] = $this->resolveFilters($this->service->getProduct($id));

        // TODO export return type?
        if ($format === 'csv') {
            return (new CSVFormat($this->service))->export($product, $variants, $download);
        } else {
            return (new JSONFormat($this->service))->export($product, $variants, $download);
        }
    }

    protected function handleUpload()
    {
        if ($_FILES === []) {
            return null;
        }

        $uploadedFile = UploadedFile::getInstanceByName('variant-uploads');
        if ($uploadedFile->type === 'text/csv') {
            return (new CSVFormat($this->service))->import($uploadedFile);
        }

        return null;
    }

    protected function handleApplyUpload(): array
    {
        $token = $this->request->getParam('token');
        $payload = Craft::$app->cache->get($token);

        Craft::$app->cache->delete($token);

        // This is temporary as we need to add support for other formats to import (not just export).

        $csvFormat = new CSVFormat($this->service);

        foreach ($payload as $productData) {
            $product = $csvFormat->resolveProductModelFromCache($productData);

            $variants = [];
            foreach ($productData['variants'] as $id => $value) {
                if (str_starts_with((string) $id, 'new')) {
                    $variants[$id] = $value;
                } else {
                    $variants[(int) $id] = $value;
                }
            }

            $product->setVariants($variants);

            Craft::$app->elements->saveElement($product, false, false, true);
        }

        return [
            $variants,
            $payload,
        ];
    }

    protected function resolveFilters($product): array
    {
        if (! ($product instanceof Product)) {
            $product = $this->service->getProduct($product);
        }

        $filterOptions = $this->parameter('filter-option');

        if ($filterOptions) {
            $options = array_map(static fn($option): array => explode('=', urldecode((string) $option)), $filterOptions);

            $variants = $this->service->getVariantsByOptions($product, $options);
        } else {
            $variants = $this->service->getVariants($product);
        }

        return [$product, $variants];
    }
}
