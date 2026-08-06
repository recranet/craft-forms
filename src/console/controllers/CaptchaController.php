<?php

namespace recranet\forms\console\controllers;

use craft\console\Controller;
use recranet\forms\captchas\CaptchaError;
use recranet\forms\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console health check for the captcha configuration. Run on deploy:
 *
 *     php craft recranet-forms/captcha/check
 *
 * Exits non-zero when the keys are missing or rejected by the provider, so a
 * broken captcha config fails the deploy instead of silently flagging all
 * form submissions in production.
 *
 * Note: Google validates the token BEFORE the secret, so a wrong-but-present
 * secret is indistinguishable from a healthy setup with our dummy token —
 * that case only surfaces at runtime, where verify() reports it as a config
 * error (visible in the CP and the notification email), never as spam.
 */
class CaptchaController extends Controller
{
	public $defaultAction = 'check';

	public function actionCheck(): int
	{
		$captcha = Plugin::getInstance()->spam->getCaptcha();

		if ($captcha === null) {
			$this->stdout('Captcha disabled (provider "none") — nothing to check.' . PHP_EOL, Console::FG_YELLOW);

			return ExitCode::OK;
		}

		try {
			// A dummy token exercises key presence and provider connectivity;
			// the expected invalid-token response proves the endpoint answered
			$captcha->verify('recranet-forms-health-check', null);
		} catch (CaptchaError $e) {
			$this->stderr('CAPTCHA PROBLEM (' . $captcha->getName() . '): ' . $e->getMessage() . PHP_EOL, Console::FG_RED);

			return ExitCode::CONFIG;
		}

		$this->stdout('Captcha OK: ' . $captcha->getName() . ' is configured and reachable.' . PHP_EOL, Console::FG_GREEN);

		return ExitCode::OK;
	}
}
