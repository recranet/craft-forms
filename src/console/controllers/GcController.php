<?php

namespace recranet\forms\console\controllers;

use craft\console\Controller;
use recranet\forms\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Explicit retention run:
 *
 *     php craft recranet-forms/gc/prune
 *
 * The same pruning also runs automatically with Craft's garbage collection;
 * this command exists for cron jobs and for verifying a retention setting.
 */
class GcController extends Controller
{
	public $defaultAction = 'prune';

	public function actionPrune(): int
	{
		$days = Plugin::getInstance()->getSettings()->getRetentionDays();

		if ($days <= 0) {
			$this->stdout('Retention disabled (retentionDays = 0) — nothing to prune.' . PHP_EOL, Console::FG_YELLOW);

			return ExitCode::OK;
		}

		$deleted = Plugin::getInstance()->retention->pruneSubmissions();
		$this->stdout("Deleted {$deleted} submission(s) older than {$days} days." . PHP_EOL, Console::FG_GREEN);

		return ExitCode::OK;
	}
}
