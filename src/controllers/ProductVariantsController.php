<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;

use craft\elements\Entry;
use craft\web\Controller;
use craft\web\Response;
use craft\web\UploadedFile;
use \craft\commerce\elements\Product;
use \craft\commerce\elements\Variant;

use fostercommerce\variantmanager\helpers\BaseController;
use fostercommerce\variantmanager\helpers\formats\CSVFormat;
use fostercommerce\variantmanager\helpers\formats\JSONFormat;

use fostercommerce\variantmanager\exceptions\BaseVariantManagerException;

class ProductVariantsController extends BaseController {
	
    public $service_name = "productVariants";

	public function actionUpload() {

		$this->requirePostRequest();

		try {
			
			$this->respond($this->handleUpload());

		} catch (BaseVariantManagerException $E) {

			$this->setStatus(500);

			$this->respond($this->formatErrorResponse(null, $E->getMessage()));

		}

	}

	public function actionApplyUpload() {

		$this->requirePostRequest();

		$this->respond($this->handleApplyUpload());

	}

	public function actionExport() {

		$payload = $this->handleExport();

		$this->respond($payload);
		
	}

	public function throwInvalidSKUsError($product, $items) {

		$message = "Sorry, but the following SKUs already exist for the given product IDs:\n\n";

		if ($product && array_key_exists($product->id, $items)) unset($items[$product->id]);

		foreach ($items as $id => $skus) {

			$normalized = implode(', ', $skus);

			$message .= "<strong>$id</strong>: $normalized\n";

		}

		throw new BaseVariantManagerException(nl2br($message));

	}

	protected function handleUpload() {

		if (!$_FILES) return null;

		$f = UploadedFile::getInstanceByName('variant-uploads');
		$payload = null;

		// Should probably use yii\helpers\FileHelper::getMimeType() 

		switch ($f->type) {

			case 'text/csv':

				$payload = (new CSVFormat($this->service))->import($f);
				break;

		}

		return $payload;

	}

	protected function handleApplyUpload() {

		$token = $this->request->getParam('token');
		$payload = Craft::$app->cache->get($token);

		Craft::$app->cache->delete($token);

		// This is temporary as we need to add support for other formats to import (not just export).

		$CSV = new CSVFormat($this->service);

		foreach ($payload as $productData) {

			$product = $CSV->resolveProductModelFromCache($productData);

			// Will clean this shit up later.

			$variants = [];
			foreach ($productData['variants'] as $id => $value) {
				
				if (str_starts_with($id, "new")) {

					$variants[$id] = $value;

				} else {

					$variants[intval($id)] = $value;

				}

			}

			$product->setVariants($variants);

			Craft::$app->elements->saveElement($product, false, false, true);

		}

		return [
			$variants,
			$payload
		];

	}

	public function handleExport() {

		$id = $this->parameter('id');
		$format = $this->parameter('format') ?? "json";
		$download = filter_var($this->parameter('download'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

		[$product, $variants] = $this->resolveFilters($this->service->getProduct($id));

		switch ($format) {

			case 'csv':
		
				$payload = (new CSVFormat($this->service))->export($product, $variants, $download);
				break;

			case 'json':
			default:

				$payload = (new JSONFormat($this->service))->export($product, $variants, $download);
				break;

		}

		return $payload;

	}

	protected function resolveFilters($product) {

		if (!($product instanceof Product)) $product = $this->service->getProduct($product);

		$filterOptions = $this->parameter('filter-option');

		if ($filterOptions) {

			$options = array_map(function ($option) { return explode("=", urldecode($option)); }, $filterOptions);

			$variants = $this->service->getVariantsByOptions($product, $options);

		} else {

			$variants = $this->service->getVariants($product);

		}

		return [$product, $variants];

	}

}
