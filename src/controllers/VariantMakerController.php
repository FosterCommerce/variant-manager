<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Queue;
use craft\web\Controller;
use fostercommerce\variantmanager\jobs\GenerateVariants;
use fostercommerce\variantmanager\models\VariantMakerSettings;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\services\VariantMaker;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class VariantMakerController extends Controller
{
	public function actionPreview(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$product = $this->product();

		$variantMaker = Plugin::getInstance()->getVariantMaker();
		$settings = $variantMaker->postedSettings() ?? new VariantMakerSettings();

		$rows = $variantMaker->plan($product, $variantMaker->selectionFromRows($settings->rows), $settings);

		return $this->asJson([
			'html' => $this->getView()->renderTemplate('variant-manager/variant-maker/_preview', [
				'rows' => $rows,
				'skuMaxLength' => VariantMaker::SKU_MAX_LENGTH,
				'generatesTitles' => VariantMaker::generatesTitles($product),
				'tracksInventory' => $settings->property(VariantMakerSettings::PROPERTY_INVENTORY_TRACKED)->include
					&& $settings->property(VariantMakerSettings::PROPERTY_INVENTORY_TRACKED)->value === true,
			]),
		]);
	}

	public function actionGenerate(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$product = $this->product();

		// The job reads the saved settings, so unsaved builder changes would generate the previous ones
		if ($this->hasUnsavedChanges($product)) {
			return $this->asFailure(Craft::t('variant-manager', 'variantMaker.saveBeforeGenerating'));
		}

		$variantMaker = Plugin::getInstance()->getVariantMaker();
		$settings = $variantMaker->getSettings($product);
		$rows = $variantMaker->plan($product, $variantMaker->selectionFromRows($settings->rows), $settings);

		// One SKU Commerce rejects rolls the whole run back, so the job would report a failure and write nothing
		foreach ($rows as $row) {
			if ($row->skuIssue !== null) {
				return $this->asFailure(Craft::t('variant-manager', 'variantMaker.skuIssuesBlockGenerating'));
			}
		}

		Queue::push(new GenerateVariants([
			'productId' => $product->getCanonicalId(),
			'generatedByUserId' => (int) static::currentUser()?->id,
		]), queue: Plugin::getInstance()->queue);

		return $this->asSuccess(Craft::t('variant-manager', 'variantMaker.queued'));
	}

	/**
	 * Renders one builder row, since its element selector criteria and input names are built server side.
	 */
	public function actionRow(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$this->product();

		$view = $this->getView();

		// The tab renders outside any namespace, so a fetched row must not pick one up either
		$html = $view->renderTemplate('variant-manager/variant-maker/_row', [
			'rowId' => $this->request->getRequiredBodyParam('rowId'),
			'attribute' => null,
			'options' => [],
			'static' => false,
		]);

		return $this->asJson([
			'html' => $html,
			'headHtml' => $view->getHeadHtml(),
			'bodyHtml' => $view->getBodyHtml(),
		]);
	}

	private function hasUnsavedChanges(Product $product): bool
	{
		return Product::find()
			->provisionalDrafts()
			->draftOf($product->getCanonicalId())
			->draftCreator(static::currentUser())
			->status(null)
			->exists();
	}

	private function product(): Product
	{
		$productId = (int) $this->request->getRequiredBodyParam('productId');
		$product = Commerce::getInstance()->getProducts()->getProductById($productId);

		if (! $product instanceof Product) {
			throw new NotFoundHttpException(Craft::t('variant-manager', 'variantMaker.productNotFound'));
		}

		$this->requirePermission("commerce-editProductType:{$product->getType()->uid}");

		return $product;
	}
}
