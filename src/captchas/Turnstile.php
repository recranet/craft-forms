<?php

namespace recranet\forms\captchas;

use craft\helpers\Html;
use craft\helpers\Template;
use recranet\forms\Plugin;
use Twig\Markup;

/**
 * Cloudflare Turnstile (experimental).
 *
 * Uses implicit rendering: the api.js script finds the .cf-turnstile element
 * and injects a hidden cf-turnstile-response input into the form.
 */
class Turnstile extends BaseCaptcha
{
	protected const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/** Error codes caused by configuration or Cloudflare availability, not by the visitor */
	protected const CONFIG_ERROR_CODES = [
		'missing-input-secret',
		'invalid-input-secret',
		'bad-request',
		'internal-error',
	];

	public function getName(): string
	{
		return 'Turnstile';
	}

	public function getResponseParamName(): string
	{
		return 'cf-turnstile-response';
	}

	public function verify(string $token, ?string $ip, ?string $expectedAction = null): CaptchaVerification
	{
		$secretKey = $this->settings->getTurnstileSecretKey();

		if ($secretKey === '') {
			throw new CaptchaError('Turnstile secret key is not configured');
		}

		$result = $this->siteVerify(self::VERIFY_URL, [
			'secret' => $secretKey,
			'response' => $token,
			'remoteip' => $ip,
		]);

		$hostname = isset($result['hostname']) ? (string)$result['hostname'] : null;

		if ($result['success'] ?? false) {
			return new CaptchaVerification(true, hostname: $hostname);
		}

		$errorCodes = (array)($result['error-codes'] ?? []);
		$this->assertNoConfigError($errorCodes, self::CONFIG_ERROR_CODES);

		return new CaptchaVerification(false, null, $errorCodes, hostname: $hostname);
	}

	public function render(?string $action = null): Markup
	{
		$siteKey = $this->settings->getTurnstileSiteKey();

		if ($siteKey === '') {
			Plugin::error('Turnstile site key is not configured — the captcha widget cannot be rendered');

			return Template::raw('<!-- recranet-forms: Turnstile site key missing -->');
		}

		$html = Html::tag('div', '', ['class' => 'cf-turnstile', 'data' => ['sitekey' => $siteKey]])
			. Html::jsFile('https://challenges.cloudflare.com/turnstile/v0/api.js', ['async' => true, 'defer' => true]);

		return Template::raw($html);
	}
}
