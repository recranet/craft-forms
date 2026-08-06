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
 * Retention resolves per form (form override, else the plugin default) and
 * per mode (delete or anonymize) — see services/Retention.
 */
class GcController extends Controller
{
	public $defaultAction = 'prune';

	public function actionPrune(): int
	{
		// No early-out on the plugin-wide setting: individual forms can carry
		// their own retention override, so the service always gets to decide
		$result = Plugin::getInstance()->retention->pruneSubmissions();

		$this->stdout(
			"Deleted {$result['deleted']} submission(s), anonymized {$result['anonymized']} submission(s)." . PHP_EOL,
			Console::FG_GREEN,
		);

		if ($result['deleted'] === 0 && $result['anonymized'] === 0 && Plugin::getInstance()->getSettings()->getRetentionDays() <= 0) {
			$this->stdout('Note: plugin-wide retention is disabled (retentionDays = 0); only forms with their own override are pruned.' . PHP_EOL, Console::FG_YELLOW);
		}

		return ExitCode::OK;
	}
}
