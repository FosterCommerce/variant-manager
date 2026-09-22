<?php

namespace fostercommerce\variantmanager\jobs;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use craft\helpers\Html;
use craft\queue\BaseJob;
use fostercommerce\variantmanager\errors\ImportDataException;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\records\Activity;
use Throwable;
use yii\base\InvalidConfigException;

class GenerateVariants extends BaseJob
{
	public int $productId;

	public int $generatedByUserId;

	public function execute($queue): void
	{
		$user = Craft::$app->getUsers()->getUserById($this->generatedByUserId);
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$product = $commerce->getProducts()->getProductById($this->productId);

		if (! $product instanceof Product) {
			return;
		}

		$variantMaker = Plugin::getInstance()->getVariantMaker();

		try {
			$counts = $variantMaker->generate($product, $variantMaker->getSettings($product));

			$link = Html::a(Html::encode((string) $product->title), (string) $product->getCpEditUrl(), [
				'class' => 'go',
			]);

			Activity::log($user, Craft::t('variant-manager', 'variantMaker.activityGenerated', [
				'product' => $link,
				'created' => $counts['created'],
				'updated' => $counts['updated'],
				'deleted' => $counts['deleted'],
			]));
		} catch (ImportDataException | InvalidConfigException $dataFailure) {
			// Do not rethrow. The same settings and the same registry produce the same error.
			$this->logFailure($user, $product, $dataFailure);
		} catch (Throwable $throwable) {
			$this->logFailure($user, $product, $throwable);

			throw $throwable;
		}
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t('variant-manager', 'jobs.generateVariants');
	}

	private function logFailure(?User $user, Product $product, Throwable $throwable): void
	{
		// The dashboard renders the message with |raw, and a product title is user supplied
		Activity::log(
			$user,
			Craft::t('variant-manager', 'variantMaker.activityFailed', [
				'product' => Html::tag('strong', Html::encode((string) $product->title)),
				'message' => Html::encode($throwable->getMessage()),
			]),
			'error'
		);

		// A job that ends rather than fails does not write a Craft log of its own
		Craft::warning("Variant generation failed for product {$product->id}: {$throwable->getMessage()}", __METHOD__);
	}
}
