<?php

namespace fostercommerce\variantmanager\services;

use Craft;
use craft\base\Component;
use craft\console\Application as ConsoleApplication;
use craft\helpers\Console;
use craft\helpers\Db;
use fostercommerce\variantmanager\Plugin;
use fostercommerce\variantmanager\records\Activity;
use yii\helpers\BaseConsole;

final class ActivityLogs extends Component
{
	public function deleteExpiredActivityLogs(): void
	{
		$this->stdout('Deleting expired activity logs ... ');
		$this->internalDeleteExpiredActivityLogs();
		$this->stdout("done\n", BaseConsole::FG_GREEN);
	}

	public function gc(): void
	{
		$this->stdout('    > deleting expired Variant Manager activity logs ... ');
		$this->internalDeleteExpiredActivityLogs();
		$this->stdout("done\n", BaseConsole::FG_GREEN);
	}

	public function clearActivityLogs(): void
	{
		$this->stdout('Deleting all activity logs ... ');
		Activity::deleteAll();
		$this->stdout("done\n", BaseConsole::FG_GREEN);
	}

	private function internalDeleteExpiredActivityLogs(): bool
	{
		$logRetention = Plugin::getInstance()->getSettings()->activityLogRetention;
		if ($logRetention === false || $logRetention === null) {
			return false;
		}

		$logRetentionInterval = \DateInterval::createFromDateString((string) $logRetention);

		if ($logRetentionInterval === false) {
			Craft::warning("Could not read activityLogRetention '{$logRetention}', so expired activity logs were kept", __METHOD__);
			return false;
		}

		$oldestActivityDate = (new \DateTime())->sub($logRetentionInterval);
		Activity::deleteAll(['<', 'dateCreated', Db::prepareDateForDb($oldestActivityDate)]);

		return true;
	}

	private function stdout(string $string, int ...$format): void
	{
		if (Craft::$app instanceof ConsoleApplication) {
			Console::stdout($string, ...$format);
		}
	}
}
