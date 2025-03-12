<?php

namespace fostercommerce\variantmanager\console\controllers;

use craft\console\Controller;
use fostercommerce\variantmanager\Plugin;
use yii\console\ExitCode;

/**
 * Activities controller
 */
class ActivitiesController extends Controller
{
	public $defaultAction = 'clear';

	/**
	 * variant-manager/activities command
	 */
	public function actionClear(bool $all = false): int
	{
		if ($all) {
			Plugin::getInstance()->activityLogs->clearActivityLogs();
		} else {
			Plugin::getInstance()->activityLogs->removeExpiredActivityLogs();
		}
		return ExitCode::OK;
	}
}
