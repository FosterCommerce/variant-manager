<?php

namespace fostercommerce\variantmanager\helpers;

use Craft;

use craft\web\Controller;

/**
 * BaseControllerTrait
 *
 * This is a base trait is intended to be used by the base controller class to avoid common logic / functions that are used.
 */
trait BaseControllerTrait
{
    use \fostercommerce\variantmanager\helpers\BaseHelper;

    private $_isPretty;

    private $_isDownload = false;

    private $_returnType;

    private $_service;

    /**
     * getService
     *
     * This is a getter function for the default service associated with the controller,
     * assigning a service based on the service_name defined if not already set.
     */
    public function getService()
    {
        if (! $this->_service && $this->service_name) {
            $name = $this->service_name;

            $this->_service = $this->plugin->{$name};
        }

        return $this->_service;
    }

    /**
     * setService
     *
     * Setter function for the assigned service class.
     */
    public function setService(mixed $service): void
    {
        $this->_service = $service;
    }

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
        return in_array($name, array_keys($this->parameters), true);
    }

    public function hasReturnType(): bool
    {
        return $this->returnType !== null;
    }

    public function getIsDownload()
    {
        return $this->_isDownload;
    }

    public function setIsDownload($value): void
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

    public function formatSuccessResponse($payload, $message = '')
    {
        return $this->formatResponse($payload, $message, true);
    }

    public function formatErrorResponse($payload, $message = '')
    {
        return $this->formatResponse($payload, $message, false);
    }

    /**
     * respond
     *
     * Get response for the controller
     */
    protected function respond(mixed $payload)
    {
        if ($this->parameter('pretty') === 'true') {
            $this->isPretty = true;
        }

        if (! $this->isDownload) {
            if ($this->hasReturnType() && $this->returnType !== 'application/json') {
                $this->respondWithType($payload);
            } else {
                $this->respondAsJson($payload);
            }
        } else {
            $this->respondAsDownload($payload);
        }
    }

    protected function respondWithType($payload, $type = null)
    {
        $type ??= $this->returnType;

        $this->response->format = \yii\web\Response::FORMAT_RAW;

        $this->response->headers
            ->set('Content-Type', sprintf('%s; charset=utf-8', $type))
            ->set('X-Content-Type-Options', 'nosniff')
            ->set('Content-Disposition', 'inline');

        $this->response->data = $payload;

        return $this->response;
    }

    /**
     * respondAsJson
     *
     * This is a helper function intended to set the response as JSON and support formatting as pretty.
     */
    protected function respondAsJson(mixed $payload)
    {
        if ($this->isPretty) {
            $payload = json_encode($payload, JSON_PRETTY_PRINT);

            $this->response->headers
                ->set('Content-Type', 'text/plain; charset=utf-8');
        }

        $this->response->format = ($this->isPretty) ? \yii\web\Response::FORMAT_RAW : \yii\web\Response::FORMAT_JSON;
        $this->response->data = $payload;

        return $this->response;
    }

    protected function respondAsDownload($payload)
    {
        if (is_array($payload)) {
            if ($this->isPretty === false) {
                $payload = json_encode($payload, JSON_THROW_ON_ERROR);
            } else {
                $payload = json_encode($payload, JSON_PRETTY_PRINT);
            }
        }

        $this->response->format = \yii\web\Response::FORMAT_RAW;
        $this->response->data = $payload;

        return $this->response;
    }
}

/**
 * BaseController
 *
 * This is a base class that is intended to be extended by other controller classes as a starting point.
 */
class BaseController extends Controller
{
    use BaseControllerTrait;

    public $service_name = '';

    public $args = [];

    protected array|bool|int $allowAnonymous = true;
}
