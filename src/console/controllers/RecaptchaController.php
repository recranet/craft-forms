<?php

namespace elloro\forms\console\controllers;

use craft\console\Controller;
use elloro\forms\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console health check for the reCAPTCHA configuration. Run on deploy:
 *
 *     php craft elloro-forms/recaptcha/check
 *
 * Exits non-zero when the keys are missing or rejected by Google, so a
 * broken captcha config fails the deploy instead of silently killing all
 * form submissions in production.
 */
class RecaptchaController extends Controller
{
	public $defaultAction = 'check';

	public function actionCheck(): int
	{
		$result = Plugin::getInstance()->recaptcha->checkConfig();

		if ($result['ok']) {
			$this->stdout('reCAPTCHA OK: ' . $result['message'] . PHP_EOL, Console::FG_GREEN);

			return ExitCode::OK;
		}

		$this->stderr('reCAPTCHA PROBLEM: ' . $result['message'] . PHP_EOL, Console::FG_RED);

		return ExitCode::CONFIG;
	}
}
