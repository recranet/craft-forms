<?php

namespace elloro\forms\services;

use Craft;
use craft\helpers\Json;
use elloro\forms\models\RecaptchaResult;
use elloro\forms\Plugin;
use GuzzleHttp\Exception\GuzzleException;
use yii\base\Component;

/**
 * reCAPTCHA v3 verification with honest error handling.
 *
 * Google's siteverify error codes are split into two buckets:
 * - Config/infra problems (invalid-input-secret, missing keys, network errors)
 *   → RecaptchaResult::ERROR. These are OUR problem, not the visitor's.
 * - Token problems (missing/invalid/expired token, low score)
 *   → RecaptchaResult::SPAM. These indicate a bot or a stale page.
 */
class Recaptcha extends Component
{
	private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

	/** Google error codes that mean the configuration is broken, not that the visitor is a bot */
	private const CONFIG_ERROR_CODES = ['invalid-input-secret', 'missing-input-secret', 'bad-request'];

	/**
	 * Verify a reCAPTCHA v3 token from a form submission.
	 */
	public function verify(?string $token): RecaptchaResult
	{
		$settings = Plugin::getInstance()->getSettings();

		if (!$settings->recaptchaEnabled) {
			return RecaptchaResult::pass(1.0);
		}

		$secret = $settings->getRecaptchaSecretKey();

		// Missing keys is a config error — do not treat every visitor as spam
		if ($secret === '' || $settings->getRecaptchaSiteKey() === '') {
			return RecaptchaResult::error('reCAPTCHA keys are not configured (check RECAPTCHA_SITE_KEY / RECAPTCHA_SECRET_KEY in .env)');
		}

		// No token at all: script blocked or bot that skipped JS — treat as spam signal
		if ($token === null || $token === '') {
			return RecaptchaResult::spam(null, 'No reCAPTCHA token submitted');
		}

		try {
			$response = Craft::createGuzzleClient(['timeout' => 5])->post(self::VERIFY_URL, [
				'form_params' => [
					'secret' => $secret,
					'response' => $token,
					'remoteip' => Craft::$app->getRequest()->getUserIP(),
				],
			]);
			$data = Json::decode((string)$response->getBody());
		} catch (GuzzleException $e) {
			// Google unreachable / timeout — infra error, not spam
			return RecaptchaResult::error('reCAPTCHA verification request failed: ' . $e->getMessage());
		}

		if (!($data['success'] ?? false)) {
			$errorCodes = $data['error-codes'] ?? [];

			if (array_intersect($errorCodes, self::CONFIG_ERROR_CODES)) {
				return RecaptchaResult::error('reCAPTCHA rejected our credentials: ' . implode(', ', $errorCodes));
			}

			// invalid-input-response, timeout-or-duplicate etc. — token problems, spam signal
			return RecaptchaResult::spam(null, 'Invalid reCAPTCHA token: ' . implode(', ', $errorCodes));
		}

		$score = (float)($data['score'] ?? 0);

		if ($score < $settings->recaptchaThreshold) {
			return RecaptchaResult::spam($score, sprintf('reCAPTCHA score %.2f below threshold %.2f', $score, $settings->recaptchaThreshold));
		}

		return RecaptchaResult::pass($score);
	}

	/**
	 * Health check for the configured keys, callable from the console
	 * (deploy) and the settings screen. Sends a dummy token: a secret-key
	 * problem surfaces as invalid-input-secret, a healthy setup returns only
	 * invalid-input-response (the dummy token being rejected, as expected).
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function checkConfig(): array
	{
		$settings = Plugin::getInstance()->getSettings();

		if (!$settings->recaptchaEnabled) {
			return ['ok' => true, 'message' => 'reCAPTCHA is disabled.'];
		}

		if ($settings->getRecaptchaSiteKey() === '' || $settings->getRecaptchaSecretKey() === '') {
			return ['ok' => false, 'message' => 'reCAPTCHA keys are missing (RECAPTCHA_SITE_KEY / RECAPTCHA_SECRET_KEY).'];
		}

		try {
			$response = Craft::createGuzzleClient(['timeout' => 5])->post(self::VERIFY_URL, [
				'form_params' => [
					'secret' => $settings->getRecaptchaSecretKey(),
					'response' => 'elloro-forms-health-check',
				],
			]);
			$data = Json::decode((string)$response->getBody());
		} catch (GuzzleException $e) {
			return ['ok' => false, 'message' => 'Could not reach Google: ' . $e->getMessage()];
		}

		$errorCodes = $data['error-codes'] ?? [];

		if (array_intersect($errorCodes, self::CONFIG_ERROR_CODES)) {
			return ['ok' => false, 'message' => 'Secret key rejected by Google: ' . implode(', ', $errorCodes)];
		}

		return ['ok' => true, 'message' => 'Secret key accepted by Google.'];
	}
}
