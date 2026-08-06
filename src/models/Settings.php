<?php

namespace recranet\forms\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings. Keys support environment variables ($RECAPTCHA_SITE_KEY etc.)
 * so per-project config stays in .env, matching the boilerplate convention.
 */
class Settings extends Model
{
	/** Whether reCAPTCHA v3 verification is enabled at all */
	public bool $recaptchaEnabled = true;

	/** reCAPTCHA v3 site key (supports $ENV_VAR syntax) */
	public string $recaptchaSiteKey = '$RECAPTCHA_SITE_KEY';

	/** reCAPTCHA v3 secret key (supports $ENV_VAR syntax) */
	public string $recaptchaSecretKey = '$RECAPTCHA_SECRET_KEY';

	/** Minimum score (0–1) a submission must get to not be flagged as spam */
	public float $recaptchaThreshold = 0.5;

	/**
	 * What to do when reCAPTCHA verification itself fails (bad keys, Google
	 * unreachable, timeout) — NOT when a submission scores as spam.
	 * true  = fail open: accept the submission, flag it, log a warning.
	 * false = fail closed: show the visitor a visible error and don't submit.
	 * Either way the failure is never silently treated as "spam".
	 */
	public bool $recaptchaFailOpen = true;

	/** Name of the hidden honeypot input; bots that fill it are flagged as spam */
	public string $honeypotName = 'rf_website';

	public function getRecaptchaSiteKey(): string
	{
		return App::parseEnv($this->recaptchaSiteKey) ?: '';
	}

	public function getRecaptchaSecretKey(): string
	{
		return App::parseEnv($this->recaptchaSecretKey) ?: '';
	}

	protected function defineRules(): array
	{
		return [
			[['recaptchaThreshold'], 'number', 'min' => 0, 'max' => 1],
			[['honeypotName'], 'required'],
		];
	}
}
