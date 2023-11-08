<?php

namespace fostercommerce\variantmanager\controllers;

use craft\commerce\elements\Product;
use craft\web\Controller;
use craft\web\UploadedFile;
use fostercommerce\variantmanager\exporters\Exporter;
use fostercommerce\variantmanager\exporters\ExportType;
use fostercommerce\variantmanager\importers\Importer;
use fostercommerce\variantmanager\importers\ImportMimeType;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class ProductVariantsController extends Controller
{
    protected array|bool|int $allowAnonymous = [
        'product-exists' => self::ALLOW_ANONYMOUS_NEVER,
        'upload' => self::ALLOW_ANONYMOUS_NEVER,
        'export' => self::ALLOW_ANONYMOUS_NEVER,
    ];

    /**
     * @throws ForbiddenHttpException
     */
    public function actionProductExists(): Response
    {
        $this->requirePermission('variant-manager:import');

        $product = Product::find()
            ->title($this->request->getQueryParam('name'))
            ->one();

        return $this->asJson([
            'exists' => isset($product),
        ]);
    }

    /**
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws ServerErrorHttpException
     */
    public function actionUpload(): void
    {
        $this->requirePostRequest();

        $this->requirePermission('variant-manager:import');

        $uploadedFile = UploadedFile::getInstanceByName('variant-uploads');

        if (! isset($uploadedFile)) {
            throw new BadRequestHttpException('No file was uploaded');
        }

        Importer::create(ImportMimeType::from($uploadedFile->type))->import($uploadedFile);
    }

    /**
     * @throws \JsonException
     * @throws NotFoundHttpException
     * @throws InvalidConfigException
     */
    public function actionExport(string $id): void
    {
        $this->requirePermission('variant-manager:export');

        $format = $this->request->getQueryParam('format', 'json');
        $download = filter_var(
            $this->request->getQueryParam('download', false),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        $exporter = Exporter::create(ExportType::from($format));
        $result = $exporter->export($id, $this->request->getBodyParams());

        if ($result === false) {
            throw new NotFoundHttpException("Product with ID {$id} not found");
        }

        if ($download) {
            $this->response->setDownloadHeaders($result['title'] . '.' . $exporter->ext, $exporter->mimetype);
            $result = $result['export'];
            if (is_array($result)) {
                $result = json_encode($result, JSON_THROW_ON_ERROR);
            }

            $this->response->format = Response::FORMAT_RAW;
        } else {
            $result = $result['export'];
            $this->response->format = Response::FORMAT_JSON;
        }

        $this->response->data = $result;
    }
}
