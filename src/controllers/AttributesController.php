<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\helpers\Queue;
use craft\web\Controller;
use fostercommerce\variantmanager\helpers\PermissionHelper;
use fostercommerce\variantmanager\jobs\BackfillAttributes;
use fostercommerce\variantmanager\jobs\PruneAttributeOrphans;
use fostercommerce\variantmanager\Plugin;
use yii\web\Response;

class AttributesController extends Controller
{
	protected array|bool|int $allowAnonymous = false;

	public function actionBackfill(): Response
	{
		$this->requirePostRequest();
		PermissionHelper::requireSaveAnyProductType();

		Queue::push(new BackfillAttributes(), queue: Plugin::getInstance()->getQueue());

		$this->setSuccessFlash(Craft::t('variant-manager', 'attributes.backfillQueued'));

		return $this->redirectToPostedUrl();
	}

	public function actionPruneOrphans(): Response
	{
		$this->requirePostRequest();
		PermissionHelper::requireSaveAnyProductType();

		// Reading every variant takes minutes on a large catalog, well past the queue's default TTR
		Queue::push(new PruneAttributeOrphans(), ttr: 3600, queue: Plugin::getInstance()->getQueue());

		$this->setSuccessFlash(Craft::t('variant-manager', 'attributes.pruneQueued'));

		return $this->redirectToPostedUrl();
	}
}
