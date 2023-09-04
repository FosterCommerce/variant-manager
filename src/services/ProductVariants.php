<?php

namespace fostercommerce\variantmanager\services;

use Craft;
use GuzzleHttp;
use fostercommerce\variantmanager\helpers\BaseService;

use \craft\commerce\elements\Product;
use \craft\commerce\elements\Variant;

/**
 * ProductVariants
 */
class ProductVariants extends BaseService {

	private $client;

	public function init() : void {
		$this->client = $this->createClient();
	}

	public function getVariants($product) {

		if (!($product instanceof Product)) $product = $this->getProduct($product);

		return $product->variants;

	}

	public function getVariantsByOptions($product, $options) {

		if (!($product instanceof Product)) $product = $this->getProduct($product);

		$map = [];
		foreach ($product->variants[0]->variantAttributes as $key => $value) $map[$value['attributeName']] = $key;

		$variants = array_filter($product->variants, function($variant) use ($map, $options) {

			foreach ($options as $option) {

				if (
					array_key_exists($option[0], $map) &&
					str_contains($variant->variantAttributes[$map[$option[0]]]['attributeValue'], $option[1])
				) return true;

			}

			return false;

		});

		return array_values($variants);

	}

	public function getProduct($product) {

		$product = Product::find()
			->id(strval($product))
			->one();

		return $product;

	}


	protected function normalizeVariant($variant) {



	}

	/**
	 * createClient
	 * 
	 * Creates a Guzzle Client.
	 *
	 * @return void
	 */
	protected function createClient() {
		return new GuzzleHttp\Client();
	}

	protected function getCommercePlugin() {

		return Craft::$app->plugins->getPlugin('commerce');

	}
	

}
