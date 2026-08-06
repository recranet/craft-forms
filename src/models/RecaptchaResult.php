<?php

namespace recranet\forms\models;

/**
 * Outcome of a reCAPTCHA verification. Distinguishes three states that the
 * stock contact-form stack collapses into one:
 *
 * - PASS:  Google verified the token and the score met the threshold.
 * - SPAM:  Google verified the token but the score was below the threshold,
 *          or the token was missing/invalid. A genuine spam signal.
 * - ERROR: Verification itself could not be performed (missing/invalid keys,
 *          Google unreachable, timeout). A configuration/infra problem — never
 *          to be confused with spam.
 */
class RecaptchaResult
{
	public const PASS = 'pass';
	public const SPAM = 'spam';
	public const ERROR = 'error';

	public function __construct(
		public readonly string $status,
		public readonly ?float $score = null,
		public readonly ?string $reason = null,
	) {
	}

	public static function pass(float $score): self
	{
		return new self(self::PASS, $score);
	}

	public static function spam(?float $score, string $reason): self
	{
		return new self(self::SPAM, $score, $reason);
	}

	public static function error(string $reason): self
	{
		return new self(self::ERROR, null, $reason);
	}

	public function isPass(): bool
	{
		return $this->status === self::PASS;
	}

	public function isSpam(): bool
	{
		return $this->status === self::SPAM;
	}

	public function isError(): bool
	{
		return $this->status === self::ERROR;
	}
}
