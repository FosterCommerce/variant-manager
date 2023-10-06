<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\web\Controller;
use craft\web\Response;

/**
 * BaseController
 *
 * This is a base class that is intended to be extended by other controller classes as a starting point.
 *
 * @property bool $isDownload
 * @property mixed $isPretty
 * @property-read mixed $parameters
 * @property mixed $returnType
 */
class BaseController extends Controller
{
    public $service_name = '';

    public $args = [];

    protected array|bool|int $allowAnonymous = true;

    private $_isPretty;

    private bool $_isDownload = false;

    private $_returnType;

    /**
     * actionIndex
     *
     * Default action assigned to index
     */
    public function actionIndex()
    {
        return null;
    }

    public function getParameters()
    {
        return $this->request->resolve()[1];
    }

    public function parameter($name)
    {
        if (! $this->hasParameter($name)) {
            return null;
        }

        return $this->parameters[$name];
    }

    public function hasParameter($name): bool
    {
        return array_key_exists($name, $this->parameters);
    }

    public function hasReturnType(): bool
    {
        return $this->returnType !== null;
    }

    public function getIsDownload(): bool
    {
        return $this->_isDownload;
    }

    public function setIsDownload(bool $value): void
    {
        $this->_isDownload = $value;
    }

    public function getIsPretty()
    {
        return $this->_isPretty;
    }

    public function setIsPretty($value): void
    {
        $this->_isPretty = $value;
    }

    public function getReturnType()
    {
        return $this->_returnType;
    }

    public function setReturnType($value): void
    {
        $this->_returnType = $value;
    }

    public function setDownloadableAs($title, $mimetype): void
    {
        $this->isDownload = true;

        $this->response->setDownloadHeaders($title, $mimetype);
    }

    /**
     * setStatus
     *
     * Sets the status code for the response.
     */
    public function setStatus(mixed $code, $message = null): void
    {
        Craft::$app->getResponse()->setStatusCode($code, $message);
    }

    public function formatResponse($payload, $message = '', $isSuccess = true): array
    {
        return [
            'payload' => $payload,
            'success' => $isSuccess,
            'message' => $message,
        ];
    }

    public function formatSuccessResponse($payload, $message = ''): array
    {
        return $this->formatResponse($payload, $message, true);
    }

    public function formatErrorResponse($payload, $message = ''): array
    {
        return $this->formatResponse($payload, $message, false);
    }

    /**
     * respond
     *
     * Get response for the controller
     *
     * @throws \JsonException
     */
    protected function respond(mixed $payload): void
    {
        if ($this->parameter('pretty') === 'true') {
            $this->isPretty = true;
        }

        if (! $this->isDownload) {
            if ($this->hasReturnType() && $this->returnType !== 'application/json') {
                $this->respondWithType($payload);
            }

            $this->respondAsJson($payload);
        }

        $this->respondAsDownload($payload);
    }

    protected function respondWithType($payload, $type = null): void
    {
        $type ??= $this->returnType;

        $this->response->format = \yii\web\Response::FORMAT_RAW;

        $this->response->headers
            ->set('Content-Type', sprintf('%s; charset=utf-8', $type))
            ->set('X-Content-Type-Options', 'nosniff')
            ->set('Content-Disposition', 'inline');

        $this->response->data = $payload;
    }

    /**
     * respondAsJson
     *
     * This is a helper function intended to set the response as JSON and support formatting as pretty.
     */
    protected function respondAsJson(mixed $payload): void
    {
        if ($this->isPretty) {
            $payload = json_encode($payload, JSON_PRETTY_PRINT);

            $this->response->headers
                ->set('Content-Type', 'text/plain; charset=utf-8');
        }

        $this->response->format = ($this->isPretty) ? \yii\web\Response::FORMAT_RAW : \yii\web\Response::FORMAT_JSON;
        $this->response->data = $payload;
    }

    /**
     * @throws \JsonException
     */
    protected function respondAsDownload($payload): void
    {
        if (is_array($payload)) {
            if ($this->isPretty === false) {
                $payload = json_encode($payload, JSON_THROW_ON_ERROR);
            } else {
                $payload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            }
        }

        $this->response->format = \yii\web\Response::FORMAT_RAW;
        $this->response->data = $payload;
    }
}
