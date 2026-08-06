<?php

namespace recranet\forms\captchas;

use Twig\Markup;

/**
 * A captcha provider that can render its widget and verify the token it
 * produces.
 */
interface CaptchaInterface
{
	/** Human-readable provider name for logs and spam reasons */
	public function getName(): string;

	/** Name of the POST parameter carrying the captcha token */
	public function getResponseParamName(): string;

	/**
	 * Whether this provider binds tokens to an action name (reCAPTCHA v3 and
	 * Enterprise do; the v2 checkbox and Turnstile do not).
	 */
	public function supportsAction(): bool;

	/**
	 * Verify a visitor token.
	 *
	 * @param string|null $expectedAction the action the form was rendered with,
	 * for providers that submit it as part of the verification request
	 *
	 * @throws CaptchaError when verification is impossible due to
	 * configuration or availability problems (never classify as spam)
	 */
	public function verify(string $token, ?string $ip, ?string $expectedAction = null): CaptchaVerification;

	/**
	 * Render the widget markup (including any required scripts).
	 *
	 * @param string|null $action action name to mint the token with, for
	 * providers that support actions
	 */
	public function render(?string $action = null): Markup;
}
