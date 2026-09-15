<?php

namespace fostercommerce\variantmanager\jobs;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Html;
use craft\queue\BaseJob;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\records\Activity;

class GenerateVariants extends BaseJob
{
	public int $productId;

	public int $generatedByUserId;

	public function execute($queue): void
	{
		$user = Craft::$app->getUsers()->getUserById($this->generatedByUserId);
		$product = Commerce::getInstance()->getProducts()->getProductById($this->productId);

		if (! $product instanceof Product) {
			return;
		}

		$variantMaker = Plugin::getInstance()->getVariantMaker();

		try {
			$counts = $variantMaker->generate($product, $variantMaker->getSettings($product));

			$link = Html::a(Html::encode($product->title), (string) $product->getCpEditUrl(), [
				'class' => 'go',
			]);

			Activity::log($user, Craft::t('variant-manager', 'variantMaker.activityGenerated', [
				'product' => $link,
				'created' => $counts['created'],
				'updated' => $counts['updated'],
				'deleted' => $counts['deleted'],
			]));
		} catch (\Throwable $throwable) {
			// The dashboard renders the message with |raw, and a product title is user supplied
			Activity::log(
				$user,
				Craft::t('variant-manager', 'variantMaker.activityFailed', [
					'product' => Html::tag('strong', Html::encode($product->title)),
					'message' => Html::encode($throwable->getMessage()),
				]),
				'error'
			);

			throw $throwable;
		}
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t('variant-manager', 'jobs.generateVariants');
	}
}
