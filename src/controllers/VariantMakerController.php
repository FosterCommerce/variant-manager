<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Queue;
use craft\web\Controller;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\helpers\PermissionHelper;
use fostercommerce\variantmanager\jobs\GenerateVariants;
use fostercommerce\variantmanager\models\VariantMakerSettings;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\services\VariantMaker;
use yii\web\ForbiddenHttpException;
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

		$selection = $variantMaker->selectionFromRows($settings->rows);
		$rows = $variantMaker->plan($product, $selection, $settings);
		$tokens = array_keys($selection);

		return $this->asJson([
			'placeholders' => $variantMaker->defaultFormats($product, $selection),
			'tokens' => $tokens === [] ? null : Craft::t('variant-manager', 'variantMaker.tokensAvailable', [
				'tokens' => '{' . implode('}, {', $tokens) . '}',
			]),
			'html' => $this->getView()->renderTemplate('variant-manager/variant-maker/_preview', [
				'rows' => $rows,
				'generatesTitles' => VariantMaker::generatesTitles($product),
				'tracksInventory' => $settings->property(VariantMakerSettings::PROPERTY_INVENTORY_TRACKED)->include
					&& $settings->property(VariantMakerSettings::PROPERTY_INVENTORY_TRACKED)->value === true,
			]),
		]);
	}

	public function actionGenerate(): ?Response
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

		// An empty plan would queue a job that reports success without writing variants
		if ($rows === []) {
			return $this->asFailure(Craft::t('variant-manager', 'variantMaker.nothingToGenerate'));
		}

		// One SKU Commerce rejects rolls the whole run back, so the job would report a failure and write nothing
		foreach ($rows as $row) {
			if ($row->skuIssue !== null) {
				return $this->asFailure(Craft::t('variant-manager', 'variantMaker.skuIssuesBlockGenerating'));
			}
		}

		Queue::push(new GenerateVariants([
			'productId' => $product->getCanonicalId(),
			'generatedByUserId' => (int) static::currentUser()?->id,
		]), queue: Plugin::getInstance()->getQueue());

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

		return $this->asJson([
			'html' => $this->rowHtml($this->requiredIntParam('rowId'), null, []),
			'headHtml' => $view->getHeadHtml(),
			'bodyHtml' => $view->getBodyHtml(),
		]);
	}

	/**
	 * Renders a row for each attribute the product's variants store, with the values they store selected.
	 */
	public function actionAutofillRows(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$product = $this->product();
		$nextRowId = $this->requiredIntParam('nextRowId');
		$postedAttributeIds = $this->request->getBodyParam('attributeIds') ?: [];
		$filledAttributeIds = array_map('intval', is_array($postedAttributeIds) ? $postedAttributeIds : []);

		$view = $this->getView();
		$html = '';

		foreach (Plugin::getInstance()->getVariantMaker()->rowsFromVariants($product) as $row) {
			if (in_array((int) $row['attribute']->id, $filledAttributeIds, true)) {
				continue;
			}

			$html .= $this->rowHtml($nextRowId++, $row['attribute'], $row['options']);
		}

		return $this->asJson([
			'html' => $html,
			'headHtml' => $view->getHeadHtml(),
			'bodyHtml' => $view->getBodyHtml(),
			'message' => $html === '' ? Craft::t('variant-manager', 'variantMaker.autofillNone') : null,
		]);
	}

	/**
	 * @param list<VariantAttribute> $options
	 */
	private function rowHtml(int $rowId, ?VariantAttribute $attribute, array $options): string
	{
		// Render a fetched row unnamespaced, because the tab itself renders outside any namespace
		return $this->getView()->renderTemplate('variant-manager/variant-maker/_row', [
			'rowId' => $rowId,
			'attribute' => $attribute,
			'options' => $options,
			'static' => false,
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

	private function requiredIntParam(string $name): int
	{
		/** @var scalar $value */
		$value = $this->request->getRequiredBodyParam($name);

		return (int) $value;
	}

	private function product(): Product
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$product = $commerce->getProducts()->getProductById($this->requiredIntParam('productId'));

		if (! $product instanceof Product) {
			throw new NotFoundHttpException(Craft::t('variant-manager', 'variantMaker.productNotFound'));
		}

		// Check Commerce, because generating variants changes the product
		if (! PermissionHelper::canSaveProductType($product->getType())) {
			throw new ForbiddenHttpException('User not authorized to edit this product type.');
		}

		return $product;
	}
}
