<?php

namespace recranet\forms\captchas;

/**
 * Result of a captcha token verification for a single visitor.
 *
 * Providers report what their API returned; deciding what it means is
 * SpamService's job, so the action/hostname policy lives in one place.
 */
class CaptchaVerification
{
	public function __construct(
		/** Whether the visitor passed the captcha */
		public readonly bool $success,
		/** Provider score when available (reCAPTCHA v3: 0 = bot, 1 = human) */
		public readonly ?float $score = null,
		/** Provider error codes for failed verifications */
		public readonly array $errorCodes = [],
		/** Action the token was generated with, when the provider reports one (reCAPTCHA v3/Enterprise) */
		public readonly ?string $action = null,
		/** Hostname the token was generated on, when the provider reports one */
		public readonly ?string $hostname = null,
	) {
	}
}
