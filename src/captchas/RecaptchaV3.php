<?php

namespace recranet\forms\captchas;

use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\Template;
use recranet\forms\Plugin;
use Twig\Markup;

/**
 * Google reCAPTCHA v3 (invisible, score-based).
 *
 * The raw score is always returned in the verification result so it can be
 * persisted with the submission, along with the action and hostname the token
 * was minted with so SpamService can reject replayed tokens.
 */
class RecaptchaV3 extends RecaptchaV2
{
	public function getName(): string
	{
		return 'reCAPTCHA v3';
	}

	public function supportsAction(): bool
	{
		return true;
	}

	public function verify(string $token, ?string $ip, ?string $expectedAction = null): CaptchaVerification
	{
		$secretKey = $this->settings->getRecaptchaSecretKey();

		if ($secretKey === '') {
			throw new CaptchaError('reCAPTCHA secret key is not configured');
		}

		$result = $this->siteVerify(static::VERIFY_URL, [
			'secret' => $secretKey,
			'response' => $token,
			'remoteip' => $ip,
		]);

		$action = isset($result['action']) ? (string)$result['action'] : null;
		$hostname = isset($result['hostname']) ? (string)$result['hostname'] : null;

		if ($result['success'] ?? false) {
			$score = isset($result['score']) ? (float)$result['score'] : null;
			$passed = $score === null || $score >= $this->settings->getRecaptchaScoreThreshold();

			return new CaptchaVerification($passed, $score, action: $action, hostname: $hostname);
		}

		$errorCodes = (array)($result['error-codes'] ?? []);
		$this->assertNoConfigError($errorCodes, static::CONFIG_ERROR_CODES);

		return new CaptchaVerification(false, null, $errorCodes, $action, $hostname);
	}

	public function render(?string $action = null): Markup
	{
		$siteKey = $this->settings->getRecaptchaSiteKey();

		if ($siteKey === '') {
			Plugin::error('reCAPTCHA site key is not configured — the captcha widget cannot be rendered');

			return Template::raw('<!-- recranet-forms: reCAPTCHA site key missing -->');
		}

		$action = $this->resolveAction($action);
		$inputId = 'rf-recaptcha-' . mt_rand();
		$encodedKey = Json::encode($siteKey);
		$encodedAction = Json::encode($action);

		// Intercept the submit, fetch a token, then re-submit with the token set.
		// If the reCAPTCHA script was blocked the form submits without a token
		// and the server reports a visible captcha error instead of spam.
		$js = <<<JS
(function () {
	var input = document.getElementById('$inputId');
	var form = input ? input.closest('form') : null;
	if (!form) return;
	var tokenReady = false;
	form.addEventListener('submit', function (e) {
		if (tokenReady || typeof grecaptcha === 'undefined') return;
		e.preventDefault();
		grecaptcha.ready(function () {
			grecaptcha.execute($encodedKey, { action: $encodedAction }).then(function (token) {
				input.value = token;
				tokenReady = true;
				if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
			}).catch(function () {
				// execute() rejected (wrong key type, grecaptcha error): submit
				// without a token so the SERVER reports a visible captcha error —
				// swallowing this would leave the form permanently unsubmittable
				tokenReady = true;
				if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
			});
		});
	});
})();
JS;

		$html = Html::hiddenInput($this->getResponseParamName(), '', ['id' => $inputId])
			. $this->renderActionField($action)
			. Html::jsFile("https://www.google.com/recaptcha/api.js?render=$siteKey", ['async' => true, 'defer' => true])
			. Html::script($js);

		// Hiding the badge is allowed by Google as long as the form shows the
		// reCAPTCHA attribution text
		if ($this->settings->recaptchaHideBadge) {
			$html .= Html::style('.grecaptcha-badge{visibility:hidden}');
		}

		return Template::raw($html);
	}
}
