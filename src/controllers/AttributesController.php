<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\helpers\Queue;
use craft\web\Controller;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\elements\VariantAttributeOption;
use fostercommerce\variantmanager\jobs\BackfillAttributes;
use fostercommerce\variantmanager\jobs\PruneAttributeOrphans;
use fostercommerce\variantmanager\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class AttributesController extends Controller
{
	protected array|bool|int $allowAnonymous = false;

	/**
	 * @throws NotFoundHttpException
	 */
	public function actionSettings(int $attributeId): Response
	{
		$this->requireAdmin(false);

		$attribute = VariantAttribute::find()->id($attributeId)->one();

		if (! $attribute instanceof VariantAttribute) {
			throw new NotFoundHttpException(Craft::t('variant-manager', 'attributes.notFound'));
		}

		$attributeConfigs = Plugin::getInstance()->getAttributeConfigs();

		return $this->renderTemplate('variant-manager/attributes/_settings', [
			'attribute' => $attribute,
			'attributeFieldLayout' => $attributeConfigs->getFieldLayout($attribute->nameKey),
			'optionFieldLayout' => $attributeConfigs->getOptionFieldLayout($attribute->nameKey),
			'readOnly' => ! Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
		]);
	}

	/**
	 * @throws NotFoundHttpException
	 */
	public function actionSaveSettings(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAdmin();

		$attributeId = (int) $this->request->getRequiredBodyParam('attributeId');
		$attribute = VariantAttribute::find()->id($attributeId)->one();

		if (! $attribute instanceof VariantAttribute) {
			throw new NotFoundHttpException(Craft::t('variant-manager', 'attributes.notFound'));
		}

		$fieldsService = Craft::$app->getFields();

		$fieldLayout = $fieldsService->assembleLayoutFromPost();
		$fieldLayout->type = VariantAttribute::class;

		$optionFieldLayout = $fieldsService->assembleLayoutFromPost('option-layout');
		$optionFieldLayout->type = VariantAttributeOption::class;

		if (! Plugin::getInstance()->getAttributeConfigs()->save($attribute->nameKey, $fieldLayout, $optionFieldLayout)) {
			$this->setFailFlash(Craft::t('variant-manager', 'attributes.settingsSaveFailed'));
			return null;
		}

		$this->setSuccessFlash(Craft::t('variant-manager', 'attributes.settingsSaved'));

		return $this->redirectToPostedUrl();
	}

	public function actionBackfill(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission('variant-manager:manage-attributes');

		Queue::push(new BackfillAttributes(), queue: Plugin::getInstance()->queue);

		$this->setSuccessFlash(Craft::t('variant-manager', 'attributes.backfillQueued'));

		return $this->redirectToPostedUrl();
	}

	public function actionPruneOrphans(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission('variant-manager:manage-attributes');

		// Reading every variant takes minutes on a large catalog, well past the queue's default TTR
		Queue::push(new PruneAttributeOrphans(), ttr: 3600, queue: Plugin::getInstance()->queue);

		$this->setSuccessFlash(Craft::t('variant-manager', 'attributes.pruneQueued'));

		return $this->redirectToPostedUrl();
	}
}
