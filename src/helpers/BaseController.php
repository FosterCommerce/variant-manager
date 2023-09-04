<?php

namespace fostercommerce\variantmanager\helpers;

use Craft;

use craft\web\Controller;
use craft\web\UrlManager;
use craft\events\RegisterUrlRulesEvent;

use yii\base\Event;


/**
 * BaseControllerTrait
 * 
 * This is a base trait is intended to be used by the base controller class to avoid common logic / functions that are used.
 * 
 */
trait BaseControllerTrait {

	use \fostercommerce\variantmanager\helpers\BaseHelper;

	private $_isPretty = null;
	private $_isDownload = false;
	private $_returnType = null;

	private $_service;
	
	/**
	 * getService
	 * 
	 * This is a getter function for the default service associated with the controller,
	 * assigning a service based on the service_name defined if not already set.
	 *
	 * @return void
	 */
	public function getService() {

		if (!$this->_service && $this->service_name) {
			
			$name = $this->service_name;

			$this->_service = $this->plugin->$name;

		}

		return $this->_service;

	}
	
	/**
	 * setService
	 * 
	 * Setter function for the assigned service class.
	 *
	 * @param  mixed $service
	 * @return void
	 */
	public function setService($service) {

		$this->_service = $service;

	}

	/**
	 * actionIndex
	 * 
	 * Default action assigned to index
	 *
	 * @return void
	 */
	public function actionIndex() {

		return null;

	}

	public function getParameters() {

		return $this->request->resolve()[1];

	}

	public function parameter($name) {

		if (!$this->hasParameter($name)) return null;

		return $this->parameters[$name];

	}

	public function hasParameter($name) {

		return in_array($name, array_keys($this->parameters));

	}

	public function hasReturnType() {

		return $this->returnType !== null;

	}

	public function getIsDownload() {

		return $this->_isDownload;

	}
	public function setIsDownload($value) {

		$this->_isDownload = $value;

	}

	public function getIsPretty() {

		return $this->_isPretty;

	}
	public function setIsPretty($value) {

		$this->_isPretty = $value;

	}

	public function getReturnType() {

		return $this->_returnType;

	}
	public function setReturnType($value) {

		$this->_returnType = $value;

	}

	public function setDownloadableAs($title, $mimetype) {

		$this->isDownload = true;
		
		$this->response->setDownloadHeaders($title, $mimetype);

	}
	
	/**
	 * respond
	 * 
	 * Get response for the controller
	 *
	 * @param  mixed $payload
	 * @param  mixed $pretty
	 * @return void
	 */
	protected function respond($payload) {	

		if ($this->parameter('pretty') == 'true') $this->isPretty = true;

		if (!$this->isDownload) {
			
			if ($this->hasReturnType() && $this->returnType != "application/json") {

				$this->respondWithType($payload);

			} else {

				$this->respondAsJson($payload);

			}

		} else {

			$this->respondAsDownload($payload);

		}

	}

	protected function respondWithType($payload, $type = null) {

		$type = $type ?? $this->returnType;

		$this->response->format = \yii\web\Response::FORMAT_RAW;

		$this->response->headers
			->set('Content-Type', "$type; charset=utf-8")
			->set('X-Content-Type-Options', 'nosniff')
			->set('Content-Disposition', 'inline');

		$this->response->data = $payload;

		return $this->response;

	}
	
	/**
	 * respondAsJson
	 * 
	 * This is a helper function intended to set the response as JSON and support formatting as pretty.
	 *
	 * @param  mixed $payload
	 * @param  mixed $pretty
	 * @return void
	 */
	protected function respondAsJson($payload) {
		
		if ($this->isPretty) {

			$payload = json_encode($payload, JSON_PRETTY_PRINT);

			$this->response->headers
				->set('Content-Type', "text/plain; charset=utf-8");

		}

		$this->response->format = ($this->isPretty) ? \yii\web\Response::FORMAT_RAW : \yii\web\Response::FORMAT_JSON;
		$this->response->data = $payload;

		return $this->response;
	
	}

	protected function respondAsDownload($payload) {

		if (is_array($payload)) {

			if ($this->isPretty === false) {
				
				$payload = json_encode($payload);

			} else {

				$payload = json_encode($payload, JSON_PRETTY_PRINT);

			}

			
		}

		$this->response->format = \yii\web\Response::FORMAT_RAW;
		$this->response->data = $payload;

		return $this->response;

	}

	/**
	 * setStatus
	 * 
	 * Sets the status code for the response.
	 *
	 * @param  mixed $code
	 * @return void
	 */
	public function setStatus($code, $message = null) {

		Craft::$app->getResponse()->setStatusCode($code, $message);
		
	}


	public function formatResponse($payload, $message = "", $isSuccess = true) {

		return [
			"payload" => $payload,
			"success" => $isSuccess,
			"message" => $message
		];

	}

	public function formatSuccessResponse($payload, $message = "") {

		return $this->formatResponse($payload, $message, true);

	}

	public function formatErrorResponse($payload, $message = "") {

		return $this->formatResponse($payload, $message, false);

	}


}

/**
 * BaseController
 * 
 * This is a base class that is intended to be extended by other controller classes as a starting point.
 * 
 */
class BaseController extends Controller {

	public $service_name = "";

	protected array|bool|int $allowAnonymous = true;

	public $args = [];

	use BaseControllerTrait;

}


