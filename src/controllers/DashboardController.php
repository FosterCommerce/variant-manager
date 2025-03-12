<?php

namespace fostercommerce\variantmanager\controllers;

use Craft;
use craft\web\Controller;
use craft\web\twig\variables\Paginate;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\records\Activity;
use fostercommerce\variantmanager\VariantManagerAssetBundle;
use yii\base\InvalidConfigException;
use yii\web\Response;

class DashboardController extends Controller
{
	final public const ACTIVITIES_PER_PAGE = 20;

	protected array|bool|int $allowAnonymous = false;

	/**
	 * @throws InvalidConfigException
	 */
	public function actionIndex(): Response
	{
		$this->view->registerAssetBundle(VariantManagerAssetBundle::class);

		$activityQuery = Activity::find()->orderBy([
			'dateCreated' => SORT_DESC,
		]);

		$status = $this->request->getQueryParam('status', 'all');

		if ($status !== 'all') {
			if ($status !== 'success' && $status !== 'error') {
				throw new \RuntimeException('Invalid log status');
			}

			$activityQuery = $activityQuery->where([
				'type' => $status,
			]);
		}

		$pageNum = Craft::$app->request->getPageNum();
		$offset = (self::ACTIVITIES_PER_PAGE * ($pageNum - 1));
		$total = $activityQuery->count();

		return $this->renderTemplate('variant-manager/dashboard', [
			'activities' => $activityQuery->limit(self::ACTIVITIES_PER_PAGE)->offset($offset)->all(),
			'logStatus' => $status,
			'pagination' => Craft::createObject([
				'class' => Paginate::class,
				'first' => $offset + 1,
				'last' => min($offset + self::ACTIVITIES_PER_PAGE, $total),
				'total' => $total,
				'currentPage' => $pageNum,
				'totalPages' => ceil($total / self::ACTIVITIES_PER_PAGE),
			]),
		]);
	}

	public function actionClearActivityLogs(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission('variant-manager:manage');

		Plugin::getInstance()->activityLogs->clearActivityLogs();

		$this->setSuccessFlash('All activity logs cleared');
		return $this->redirectToPostedUrl();
	}
}
