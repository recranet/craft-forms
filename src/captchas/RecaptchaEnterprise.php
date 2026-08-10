<?php

namespace recranet\forms\captchas;

use Craft;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\Template;
use GuzzleHttp\Exception\GuzzleException;
use recranet\forms\Plugin;
use Twig\Markup;

/**
 * Google reCAPTCHA Enterprise (score-based, via projects.assessments.create).
 *
 * Google's replacement for the legacy siteverify endpoint: requires a Cloud
 * project ID and API key, returns the real risk score, and fails closed on
 * quota (HTTP 429) instead of siteverify's silent fail-open — which this
 * plugin surfaces as a visible error, never as spam.
 */
class RecaptchaEnterprise extends BaseCaptcha
{
	private const ASSESSMENT_URL = 'https://recaptchaenterprise.googleapis.com/v1/projects/%s/assessments?key=%s';

	public function getName(): string
	{
		return 'reCAPTCHA Enterprise';
	}

	public function getResponseParamName(): string
	{
		return 'g-recaptcha-response';
	}

	public function supportsAction(): bool
	{
		return true;
	}

	public function verify(string $token, ?string $ip, ?string $expectedAction = null): CaptchaVerification
	{
		$siteKey = $this->settings->getRecaptchaSiteKey();
		$projectId = $this->settings->getRecaptchaProjectId();
		$apiKey = $this->settings->getRecaptchaApiKey();

		if ($siteKey === '' || $projectId === '' || $apiKey === '') {
			throw new CaptchaError('reCAPTCHA Enterprise requires the site key, Cloud project ID and API key to be configured');
		}

		$url = sprintf(self::ASSESSMENT_URL, rawurlencode($projectId), rawurlencode($apiKey));
		$expectedAction = $this->resolveAction($expectedAction);

		try {
			$response = Craft::createGuzzleClient(['timeout' => 5])->post($url, [
				'json' => [
					'event' => [
						'token' => $token,
						'siteKey' => $siteKey,
						'expectedAction' => $expectedAction,
						'userIpAddress' => $ip,
					],
				],
			]);
		} catch (GuzzleException $e) {
			// 400/403 = bad credentials or project, 429 = quota exhausted
			// (fails closed), otherwise network trouble — all our problem,
			// never the visitor's. Keep the API key out of logs.
			$message = str_replace($apiKey, '***', $e->getMessage());

			throw new CaptchaError(sprintf('reCAPTCHA Enterprise assessment request failed: %s', $message), 0, $e);
		}

		$result = Json::decodeIfJson((string)$response->getBody());

		if (!is_array($result)) {
			throw new CaptchaError('reCAPTCHA Enterprise returned a malformed response');
		}

		$tokenProperties = $result['tokenProperties'] ?? [];
		$score = isset($result['riskAnalysis']['score']) ? (float)$result['riskAnalysis']['score'] : null;
		$action = isset($tokenProperties['action']) ? (string)$tokenProperties['action'] : null;
		$hostname = isset($tokenProperties['hostname']) ? (string)$tokenProperties['hostname'] : null;

		// An invalid/expired token is a visitor problem: spam
		if (!($tokenProperties['valid'] ?? false)) {
			$reason = strtolower((string)($tokenProperties['invalidReason'] ?? 'invalid'));

			return new CaptchaVerification(false, $score, [$reason], $action, $hostname);
		}

		$passed = $score === null || $score >= $this->settings->getRecaptchaScoreThreshold();

		// The action/hostname comparison is SpamService's job — it holds the
		// policy for every provider, so it stays in one place
		return new CaptchaVerification($passed, $score, action: $action, hostname: $hostname);
	}

	public function render(?string $action = null): Markup
	{
		$siteKey = $this->settings->getRecaptchaSiteKey();

		if ($siteKey === '') {
			Plugin::error('reCAPTCHA site key is not configured — the captcha widget cannot be rendered');

			return Template::raw('<!-- recranet-forms: reCAPTCHA site key missing -->');
		}

		$action = $this->resolveAction($action);
		$inputId = 'rf-recaptcha-ent-' . mt_rand();
		$encodedKey = Json::encode($siteKey);
		$encodedAction = Json::encode($action);

		// Same submit interception as v3, but through the enterprise.js API.
		// If the script was blocked the form submits without a token and the
		// server reports a visible captcha error instead of spam.
		$js = <<<JS
(function () {
	var input = document.getElementById('$inputId');
	var form = input ? input.closest('form') : null;
	if (!form) return;
	var tokenReady = false;
	form.addEventListener('submit', function (e) {
		if (tokenReady || typeof grecaptcha === 'undefined' || !grecaptcha.enterprise) return;
		e.preventDefault();
		grecaptcha.enterprise.ready(function () {
			grecaptcha.enterprise.execute($encodedKey, { action: $encodedAction }).then(function (token) {
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
			. Html::jsFile("https://www.google.com/recaptcha/enterprise.js?render=$siteKey", ['async' => true, 'defer' => true])
			. Html::script($js);

		// Hiding the badge is only allowed by Google when the form shows the
		// reCAPTCHA attribution text — the helper renders both together
		if ($this->settings->recaptchaHideBadge) {
			$html .= $this->renderHiddenBadgeNotice();
		}

		return Template::raw($html);
	}
}
