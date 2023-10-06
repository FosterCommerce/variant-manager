<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\errors\ElementNotFoundException;
use craft\web\UploadedFile;
use fostercommerce\variantmanager\exceptions\InvalidSkusException;
use fostercommerce\variantmanager\helpers\formats\CSVFormat;
use fostercommerce\variantmanager\helpers\formats\JSONFormat;
use fostercommerce\variantmanager\VariantManager;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;

class ProductVariantsController extends BaseController
{
    public $service_name = 'productVariants';

    /**
     * @throws BadRequestHttpException
     */
    public function actionUpload(): void
    {
        $this->requirePostRequest();

        try {
            $this->respond($this->handleUpload());
        } catch (Throwable $throwable) {
            $this->setStatus(500);
            $this->respond($this->formatErrorResponse(null, $throwable->getMessage()));
        }
    }

    /**
     * @throws BadRequestHttpException
     */
    public function actionApplyUpload(): void
    {
        $this->requirePostRequest();

        try {
            $this->respond($this->handleApplyUpload());
        } catch (Throwable $throwable) {
            $this->setStatus(500);
            $this->respond($this->formatErrorResponse(null, $throwable->getMessage()));
        }
    }

    public function actionExport(): void
    {
        $payload = $this->handleExport();
        $this->respond($payload);
    }

    public function handleExport(): void
    {
        $id = $this->parameter('id');
        $format = $this->parameter('format') ?? 'json';
        $download = filter_var($this->parameter('download'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        [$product, $variants] = $this->resolveFilters(VariantManager::getInstance()->productVariants->getProduct($id));

        // TODO export return type?
        if ($format === 'csv') {
            $formatter = new CSVFormat();
        } else {
            $formatter = new JSONFormat();
        }

        if ($download) {
            $this->setDownloadableAs($product->title . '.' . $formatter->ext, $formatter->mimetype);
        }

        if (! $download) {
            $this->returnType = $formatter->returnType;
        }

        $this->respond($formatter->export($product, $variants));
    }

    /**
     * @throws Exception
     * @throws InvalidSkusException
     */
    protected function handleUpload(): ?array
    {
        if ($_FILES === []) {
            return null;
        }

        $uploadedFile = UploadedFile::getInstanceByName('variant-uploads');
        if ($uploadedFile?->type === 'text/csv') {
            return (new CSVFormat())->import($uploadedFile);
        }

        return null;
    }

    /**
     * @throws ElementNotFoundException
     * @throws InvalidConfigException
     * @throws Exception
     * @throws Throwable
     */
    protected function handleApplyUpload(): array
    {
        $token = $this->request->getParam('token');
        $payload = Craft::$app->cache->get($token);

        Craft::$app->cache->delete($token);

        // This is temporary as we need to add support for other formats to import (not just export).

        $csvFormat = new CSVFormat();

        $variants = [];
        foreach ($payload as $productData) {
            $product = $csvFormat->resolveProductModelFromCache($productData);

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

    protected function resolveFilters(Product $product): array
    {
        $productVariants = VariantManager::getInstance()->productVariants;

        $filterOptions = $this->parameter('filter-option');

        if ($filterOptions) {
            $options = array_map(static fn($option): array => explode('=', urldecode((string) $option)), $filterOptions);

            $variants = $productVariants->getVariantsByOptions($product, $options);
        } else {
            $variants = $productVariants->getVariants($product);
        }

        return [$product, $variants];
    }
}
