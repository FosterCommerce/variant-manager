<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\helpers\Cp;
use craft\helpers\Html;
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

		$readOnly = ! Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

		$response = $this->asCpScreen()
			->title($fieldSet->uid === null ? Craft::t('variant-manager', 'fieldSets.newFieldSet') : (string) $fieldSet->name)
			->addCrumb(Craft::t('app', 'Settings'), 'settings')
			->addCrumb(Craft::t('app', 'Plugins'), 'settings/plugins')
			->addCrumb(Craft::t('variant-manager', 'plugin.name'), 'variant-manager/settings')
			->contentTemplate('variant-manager/field-sets/_edit', [
				'fieldSet' => $fieldSet,
				'readOnly' => $readOnly,
			]);

		if ($fieldSet->uid !== null) {
			$response->metaSidebarHtml(Cp::metadataHtml([
				Craft::t('variant-manager', 'fieldSets.usedBy') => fn (): string => $this->usedByHtml($fieldSet),
			]));
		}

		if ($readOnly) {
			$response->noticeHtml(Cp::readOnlyNoticeHtml());

			return $response;
		}

		$response
			->action('variant-manager/field-sets/save')
			->redirectUrl('variant-manager/settings')
			->addAltAction(Craft::t('app', 'Save and continue editing'), [
				'redirect' => 'variant-manager/field-sets/{uid}',
				'shortcut' => true,
				'retainScroll' => true,
			]);

		if ($fieldSet->uid !== null) {
			$response->addAltAction(Craft::t('variant-manager', 'fieldSets.delete'), [
				'action' => 'variant-manager/field-sets/delete',
				'redirect' => 'variant-manager/settings',
				'confirm' => Craft::t('variant-manager', 'fieldSets.deleteConfirm'),
				'destructive' => true,
			]);
		}

		return $response;
	}

	/**
	 * @throws BadRequestHttpException
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

		/** @var string|null $name */
		$name = $this->request->getBodyParam('name');
		$fieldSet->name = (string) $name;

		/** @var string|null $handle */
		$handle = $this->request->getBodyParam('handle');
		$fieldSet->handle = (string) $handle;

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

		return $this->redirectToPostedUrl($fieldSet);
	}

	/**
	 * @throws BadRequestHttpException
	 */
	public function actionDelete(): Response
	{
		$this->requirePostRequest();
		$this->requireAdmin();

		$fieldSets = Plugin::getInstance()->getFieldSets();
		/** @var string $fieldSetUid */
		$fieldSetUid = $this->request->getRequiredBodyParam('fieldSetUid');

		if ($fieldSets->getAttributesUsingFieldSet($fieldSetUid) !== []) {
			$this->setFailFlash(Craft::t('variant-manager', 'fieldSets.deleteInUse'));
			return $this->redirectToPostedUrl();
		}

		$fieldSets->delete($fieldSetUid);

		$this->setSuccessFlash(Craft::t('variant-manager', 'fieldSets.deleted'));

		return $this->redirectToPostedUrl();
	}

	private function usedByHtml(FieldSet $fieldSet): string
	{
		$attributes = Plugin::getInstance()->getFieldSets()->getAttributesUsingFieldSet((string) $fieldSet->uid);

		if ($attributes === []) {
			return Html::tag('i', Craft::t('variant-manager', 'fieldSets.noAttributes'));
		}

		return Html::ul(array_map(
			static fn (VariantAttribute $attribute): string => Cp::elementChipHtml($attribute),
			$attributes
		), [
			'encode' => false,
		]);
	}

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
