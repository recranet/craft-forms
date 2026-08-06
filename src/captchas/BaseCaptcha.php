<?php

namespace recranet\forms\captchas;

use Craft;
use craft\helpers\Html;
use GuzzleHttp\Exception\GuzzleException;
use recranet\forms\FormFields;
use recranet\forms\models\Settings;
use recranet\forms\Plugin;

/**
 * Shared verification plumbing for HTTP-based captcha providers.
 */
abstract class BaseCaptcha implements CaptchaInterface
{
	/** Action used when a form does not name one */
	public const DEFAULT_ACTION = 'submit';

	/**
	 * Google only accepts alphanumerics, slashes and underscores in action
	 * names, and silently drops anything else — which would look like a
	 * mismatch on our side rather than a template mistake.
	 */
	private const ACTION_PATTERN = '/^[A-Za-z0-9\/_]+$/';

	public function __construct(protected Settings $settings)
	{
	}

	public function supportsAction(): bool
	{
		return false;
	}

	/**
	 * POST to the provider's siteverify endpoint and return the decoded JSON.
	 *
	 * @throws CaptchaError when the request fails or returns malformed data —
	 * an unreachable verification API is an availability problem, not spam
	 */
	protected function siteVerify(string $url, array $params): array
	{
		try {
			$response = Craft::createGuzzleClient(['timeout' => 5])
				->post($url, ['form_params' => $params]);
		} catch (GuzzleException $e) {
			throw new CaptchaError(sprintf('%s verification request failed: %s', $this->getName(), $e->getMessage()), 0, $e);
		}

		$result = json_decode((string)$response->getBody(), true);

		if (!is_array($result)) {
			throw new CaptchaError(sprintf('%s verification returned a malformed response', $this->getName()));
		}

		return $result;
	}

	/**
	 * Split provider error codes into config errors (our problem) and
	 * visitor errors (their problem), and throw for config errors.
	 *
	 * @throws CaptchaError
	 */
	protected function assertNoConfigError(array $errorCodes, array $configErrorCodes): void
	{
		$configErrors = array_intersect($errorCodes, $configErrorCodes);

		if ($configErrors !== []) {
			throw new CaptchaError(sprintf(
				'%s rejected the verification request due to a configuration problem: %s (check the site/secret keys and the domain allowlist)',
				$this->getName(),
				implode(', ', $configErrors)
			));
		}
	}

	/**
	 * The action to mint a token with: the requested one when it is usable,
	 * otherwise the default. An unusable name is a template mistake, so it is
	 * logged as a real error rather than left to fail verification later.
	 */
	protected function resolveAction(?string $action): string
	{
		$action = trim((string)$action);

		if ($action === '') {
			return self::DEFAULT_ACTION;
		}

		if (!preg_match(self::ACTION_PATTERN, $action)) {
			Plugin::error(sprintf(
				'Captcha action "%s" contains characters Google does not accept (only letters, digits, "/" and "_") — falling back to "%s"',
				$action,
				self::DEFAULT_ACTION
			));

			return self::DEFAULT_ACTION;
		}

		return $action;
	}

	/**
	 * Hidden field carrying the hashed action, so the server knows which action
	 * to expect without trusting the POST body.
	 */
	protected function renderActionField(string $action): string
	{
		return Html::hiddenInput(
			FormFields::CAPTCHA_ACTION,
			Craft::$app->getSecurity()->hashData($action)
		);
	}
}
