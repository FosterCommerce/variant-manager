<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\models\FieldLayout;
use craft\web\Controller;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\models\FieldSet;
use fostercommerce\variantmanager\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FieldSetsController extends Controller
{
	protected array|bool|int $allowAnonymous = false;

	/**
	 * @throws NotFoundHttpException
	 */
	public function actionEdit(?string $fieldSetUid = null, ?FieldSet $fieldSet = null): Response
	{
		$this->requireAdmin(false);

		$fieldSets = Plugin::getInstance()->getFieldSets();

		// Repopulate from a failed save, so the form still shows what was typed
		$fieldSet ??= $fieldSetUid === null ? new FieldSet() : $fieldSets->getFieldSetByUid($fieldSetUid);

		if (! $fieldSet instanceof FieldSet) {
			throw new NotFoundHttpException(Craft::t('variant-manager', 'fieldSets.notFound'));
		}

		return $this->renderTemplate('variant-manager/field-sets/_edit', [
			'fieldSet' => $fieldSet,
			'attributesUsing' => $fieldSet->uid === null ? [] : $fieldSets->getAttributesUsingFieldSet($fieldSet->uid),
			'readOnly' => ! Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
		]);
	}

	/**
	 * @throws NotFoundHttpException
	 */
	public function actionSave(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAdmin();

		$fieldSets = Plugin::getInstance()->getFieldSets();
		$fieldSetUid = $this->request->getBodyParam('fieldSetUid') ?: null;

		$fieldSet = is_string($fieldSetUid)
			? $fieldSets->getFieldSetByUid($fieldSetUid)
			: new FieldSet();

		if (! $fieldSet instanceof FieldSet) {
			throw new NotFoundHttpException(Craft::t('variant-manager', 'fieldSets.notFound'));
		}

		$fieldSet->name = $this->request->getBodyParam('name');

		$fieldSet->setFieldLayout($this->layoutFromPost());
		$fieldSet->setOptionFieldLayout($this->layoutFromPost('option-layout'));

		if (! $fieldSets->save($fieldSet)) {
			$this->setFailFlash(Craft::t('variant-manager', 'fieldSets.saveFailed'));

			Craft::$app->getUrlManager()->setRouteParams([
				'fieldSet' => $fieldSet,
			]);

			return null;
		}

		$this->setSuccessFlash(Craft::t('variant-manager', 'fieldSets.saved'));

		return $this->redirectToPostedUrl();
	}

	public function actionDelete(): Response
	{
		$this->requirePostRequest();
		$this->requireAdmin();

		$fieldSets = Plugin::getInstance()->getFieldSets();
		$fieldSetUid = (string) $this->request->getRequiredBodyParam('fieldSetUid');

		if ($fieldSets->getAttributesUsingFieldSet($fieldSetUid) !== []) {
			$this->setFailFlash(Craft::t('variant-manager', 'fieldSets.deleteInUse'));
			return $this->redirectToPostedUrl();
		}

		$fieldSets->delete($fieldSetUid);

		$this->setSuccessFlash(Craft::t('variant-manager', 'fieldSets.deleted'));

		return $this->redirectToPostedUrl();
	}

	/**
	 * @throws BadRequestHttpException
	 */
	/**
	 * Set the type before the tabs. setTabs() memoizes the layout's native fields.
	 */
	private function layoutFromPost(?string $namespace = null): FieldLayout
	{
		$fieldLayout = new FieldLayout([
			'type' => VariantAttribute::class,
		]);
		$fieldLayout->setTabs(Craft::$app->getFields()->assembleLayoutFromPost($namespace)->getTabs());

		return $fieldLayout;
	}
}
