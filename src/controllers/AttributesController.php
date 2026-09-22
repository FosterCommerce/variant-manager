<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\Db;
use craft\helpers\Queue;
use craft\web\Controller;
use fostercommerce\variantmanager\db\Table;
use fostercommerce\variantmanager\elements\VariantAttribute;
use fostercommerce\variantmanager\helpers\PermissionHelper;
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

		$attribute = VariantAttribute::find()->attributeId(0)->id($attributeId)->one();

		if (! $attribute instanceof VariantAttribute) {
			throw new NotFoundHttpException(Craft::t('variant-manager', 'attributes.notFound'));
		}

		return $this->renderTemplate('variant-manager/attributes/_settings', [
			'attribute' => $attribute,
			'fieldSets' => Plugin::getInstance()->getFieldSets()->getAllFieldSets(),
		]);
	}

	/**
	 * @throws NotFoundHttpException
	 */
	public function actionSaveSettings(): ?Response
	{
		$this->requirePostRequest();
		// The assignment is a column on the attribute, not project config, so a locked-down environment can still set it
		$this->requireAdmin(false);

		$attributeId = (int) $this->request->getRequiredBodyParam('attributeId');
		$attribute = VariantAttribute::find()->attributeId(0)->id($attributeId)->one();

		if (! $attribute instanceof VariantAttribute) {
			throw new NotFoundHttpException(Craft::t('variant-manager', 'attributes.notFound'));
		}

		$fieldSetUid = $this->request->getBodyParam('fieldSetUid') ?: null;
		$attribute->fieldSetUid = is_string($fieldSetUid) ? $fieldSetUid : null;

		if (! Craft::$app->getElements()->saveElement($attribute)) {
			$this->setFailFlash(Craft::t('variant-manager', 'attributes.settingsSaveFailed'));
			return null;
		}

		// Carry it onto any open draft, because applying one writes that row back over the canonical
		Db::update(Table::ATTRIBUTES, [
			'fieldSetUid' => $attribute->fieldSetUid,
		], [
			'id' => (new Query())
				->select(['id'])
				->from(CraftTable::ELEMENTS)
				->where([
					'canonicalId' => $attribute->id,
				]),
		]);

		$this->setSuccessFlash(Craft::t('variant-manager', 'attributes.settingsSaved'));

		return $this->redirectToPostedUrl();
	}

	public function actionBackfill(): Response
	{
		$this->requirePostRequest();
		PermissionHelper::requireSaveAnyProductType();

		Queue::push(new BackfillAttributes(), queue: Plugin::getInstance()->queue);

		$this->setSuccessFlash(Craft::t('variant-manager', 'attributes.backfillQueued'));

		return $this->redirectToPostedUrl();
	}

	public function actionPruneOrphans(): Response
	{
		$this->requirePostRequest();
		PermissionHelper::requireSaveAnyProductType();

		// Reading every variant takes minutes on a large catalog, well past the queue's default TTR
		Queue::push(new PruneAttributeOrphans(), ttr: 3600, queue: Plugin::getInstance()->queue);

		$this->setSuccessFlash(Craft::t('variant-manager', 'attributes.pruneQueued'));

		return $this->redirectToPostedUrl();
	}
}
