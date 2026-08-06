<?php

namespace recranet\forms\models;

/**
 * Outcome of the spam pipeline for a single submission.
 */
class SpamVerdict
{
	public function __construct(
		public bool $isSpam = false,
		/** Captcha score when the provider reports one (reCAPTCHA v3/Enterprise) */
		public ?float $score = null,
		/** Why the submission was classified as spam (honeypot, captcha-failed, captcha-score, blocklist, too-fast) */
		public ?string $reason = null,
		/**
		 * Definite spam (honeypot hit, forged timestamp or score below the
		 * reject threshold): rejected outright and never stored, so the
		 * reviewable spam list only contains gray-zone submissions that might
		 * be false positives.
		 */
		public bool $reject = false,
	) {
	}
}
