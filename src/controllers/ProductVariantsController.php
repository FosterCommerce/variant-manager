<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\errors\ElementNotFoundException;
use craft\web\Controller;
use craft\web\UploadedFile;
use fostercommerce\variantmanager\exceptions\InvalidSkusException;
use fostercommerce\variantmanager\exporters\Exporter;
use fostercommerce\variantmanager\importers\Importer;
use fostercommerce\variantmanager\VariantManager;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class ProductVariantsController extends Controller
{
    protected array|bool|int $allowAnonymous = [
        'upload' => self::ALLOW_ANONYMOUS_NEVER,
        'apply-upload' => self::ALLOW_ANONYMOUS_NEVER,
        // TODO this needs to be _NEVER once dependent sites have been updated to remove usage of this action.
        'export' => self::ALLOW_ANONYMOUS_LIVE,
    ];

    /**
     * @throws BadRequestHttpException|\JsonException
     * @throws ForbiddenHttpException
     * @throws ServerErrorHttpException
     */
    public function actionUpload(): void
    {
        $this->requirePostRequest();
        // TODO update to use $this->requiresPermission(..) instead.
        $this->requireAdmin();

        try {
            $this->response = $this->asJson($this->handleUpload());
        } catch (Throwable $throwable) {
            throw new ServerErrorHttpException($throwable->getMessage());
        }
    }

    /**
     * @throws BadRequestHttpException|
     * @throws ForbiddenHttpException
     * @throws ServerErrorHttpException
     */
    public function actionApplyUpload(): void
    {
        $this->requirePostRequest();
        // TODO update to use $this->requiresPermission(..) instead.
        $this->requireAdmin();

        try {
            $this->handleApplyUpload();
        } catch (Throwable $throwable) {
            throw new ServerErrorHttpException($throwable->getMessage());
        }
    }

    /**
     * @throws \JsonException
     * @throws NotFoundHttpException
     */
    public function actionExport(string $id): void
    {
        // TODO update to use $this->requiresPermission(..) for exporting data.
        $format = $this->request->getQueryParam('format', 'json');
        $download = filter_var(
            $this->request->getQueryParam('download', false),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        /** @var Product|null $product */
        $product = Product::find()
            ->id($id)
            ->one();

        $variants = $this->resolveFilters($product);

        if (! isset($product)) {
            throw new NotFoundHttpException("Product with ID {$id} not found");
        }

        $exporter = Exporter::create($format);

        $result = $exporter->export($product, $variants);

        if ($download) {
            $this->response->setDownloadHeaders($product->title . '.' . $exporter->ext, $exporter->mimetype);
            if (is_array($result)) {
                $result = json_encode($result, JSON_THROW_ON_ERROR);
            }

            $this->response->format = Response::FORMAT_RAW;
        } else {
            $this->response->format = Response::FORMAT_JSON;
        }

        $this->response->data = $result;
    }

    /**
     * @throws Exception
     * @throws InvalidSkusException
     */
    private function handleUpload(): ?array
    {
        $uploadedFile = UploadedFile::getInstanceByName('variant-uploads');

        if (! isset($uploadedFile)) {
            throw new BadRequestHttpException('No file was uploaded');
        }

        return Importer::create($uploadedFile->type)->import($uploadedFile);
    }

    /**
     * @throws ElementNotFoundException
     * @throws InvalidConfigException
     * @throws Exception
     * @throws Throwable
     */
    private function handleApplyUpload(): void
    {
        $token = $this->request->getParam('token');
        $payload = Craft::$app->cache->get($token);

        $type = $payload['type'];
        $payload = $payload['payload'];

        Craft::$app->cache->delete($token);

        // This is temporary as we need to add support for other formats to import (not just export).

        $variants = [];
        // TODO JsonImporter doesn't have a way to import files
        $product = Importer::create($type)->resolveProductModelFromCache($payload);

        foreach ($payload['variants'] as $id => $value) {
            if (str_starts_with((string) $id, 'new')) {
                $variants[$id] = $value;
            } else {
                $variants[(int) $id] = $value;
            }
        }

        $product->setVariants($variants);

        Craft::$app->elements->saveElement($product, false, false, true);
    }

    /**
     * @return Variant[]
     */
    private function resolveFilters(Product $product): array
    {
        $productVariants = VariantManager::getInstance()->productVariants;

        $options = $this->request->getQueryParam('filter-option');

        if ($options) {
            return $productVariants->getVariantsByOptions($product, $options);
        }

        return $product->variants;
    }
}
