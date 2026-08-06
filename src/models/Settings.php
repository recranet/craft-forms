<?php

namespace recranet\forms\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings. Keys support environment variables ($RECAPTCHA_SITE_KEY etc.)
 * so per-project config stays in .env, matching the boilerplate convention.
 *
 * Spam pipeline order (see services/SpamService): blocklist → honeypot →
 * throttle → submit timing → captcha (with token binding). Cheap local checks
 * run first so a bot never costs a captcha verification.
 */
class Settings extends Model
{
	public const CAPTCHA_NONE = 'none';
	public const CAPTCHA_RECAPTCHA_V2 = 'recaptcha-v2';
	public const CAPTCHA_RECAPTCHA_V3 = 'recaptcha-v3';
	public const CAPTCHA_RECAPTCHA_ENTERPRISE = 'recaptcha-enterprise';
	public const CAPTCHA_TURNSTILE = 'turnstile';

	/** Which captcha provider to use; 'none' disables captcha verification */
	public string $captchaProvider = self::CAPTCHA_RECAPTCHA_V3;

	/** reCAPTCHA site key (v2/v3/Enterprise; supports $ENV_VAR syntax) */
	public string $recaptchaSiteKey = '$RECAPTCHA_SITE_KEY';

	/** reCAPTCHA secret key (v2/v3; supports $ENV_VAR syntax) */
	public string $recaptchaSecretKey = '$RECAPTCHA_SECRET_KEY';

	/** reCAPTCHA Enterprise: Google Cloud project ID */
	public string $recaptchaProjectId = '';

	/** reCAPTCHA Enterprise: API key */
	public string $recaptchaApiKey = '';

	/** Cloudflare Turnstile site key */
	public string $turnstileSiteKey = '';

	/** Cloudflare Turnstile secret key */
	public string $turnstileSecretKey = '';

	/** Minimum score (0–1) a scored submission needs to pass */
	public float|string $recaptchaThreshold = 0.5;

	/**
	 * Below this score a submission is definite spam: rejected outright and
	 * not stored. Between reject and pass thresholds it is stored as
	 * reviewable spam.
	 */
	public float|string $recaptchaRejectThreshold = 0.3;

	/** Hide the reCAPTCHA badge (requires showing the attribution text in the form) */
	public bool $recaptchaHideBadge = false;

	/**
	 * What to do when captcha verification itself fails (bad keys, provider
	 * unreachable, timeout) — NOT when a submission scores as spam.
	 * true  = fail open: accept the submission, flag it, log a warning.
	 * false = fail closed: show the visitor a visible error and don't submit.
	 * Either way the failure is never silently treated as "spam".
	 */
	public bool $recaptchaFailOpen = true;

	/** Whether the hidden honeypot field is rendered and checked */
	public bool $honeypotEnabled = true;

	/** Name of the hidden honeypot input; bots that fill it are rejected */
	public string $honeypotName = 'rf_website';

	/**
	 * Submissions arriving faster than this many seconds after the form was
	 * rendered are rejected as bots. 0 disables the check.
	 */
	public int|string $minSubmitSeconds = 3;

	/**
	 * Maximum number of submissions a single IP may make to a single form
	 * within the throttle window. Exceeding submits are rejected outright —
	 * a real visitor doesn't submit the same form 6 times a minute, but a
	 * bot hammering the endpoint does. 0 disables the check.
	 */
	public int|string $throttleCount = 5;

	/**
	 * Rolling window (in seconds) for the submission throttle. 0 disables
	 * the check.
	 */
	public int|string $throttleWindow = 60;

	/**
	 * Verify that v3/Enterprise tokens were minted on one of our hostnames,
	 * so tokens farmed on another site sharing the key cannot be replayed.
	 */
	public bool $verifyCaptchaHostname = true;

	/** Comma-separated hostname allowlist; empty = the request's hostname */
	public string $captchaAllowedHostnames = '';

	/**
	 * Comma-separated sender blocklist for human-driven spam a captcha score
	 * cannot catch. Entry shapes: full address (someone@example.com), domain
	 * suffix (@spamdomain.ru), local-part prefix (someone), IP prefix
	 * (198.51.100. or 2001:1c00:).
	 */
	public string $blocklist = '';

	/**
	 * Store submissions in the database. Off = notification emails still go
	 * out, but nothing is persisted (mail-only mode; a send failure is then
	 * only visible in the logs).
	 */
	public bool $saveSubmissions = true;

	/** Store spam-flagged submissions (for review); off = spam is dropped */
	public bool $saveSpamSubmissions = true;

	/**
	 * Delete stored submissions older than this many days during Craft's
	 * garbage collection. 0 keeps them forever. AVG/GDPR: match this to what
	 * the privacy statement promises.
	 */
	public int|string $retentionDays = 0;

	/**
	 * Handle of the asset volume file-field uploads are stored in (in a
	 * subfolder named after the form handle). Empty = file fields are not
	 * usable: an uploaded file becomes a validation error on that field.
	 */
	public string $uploadVolume = '';

	/** Maximum upload size for file fields, in MB */
	public int|string $maxUploadSize = 8;

	/**
	 * Comma-separated extension allowlist for file fields. Deliberately
	 * conservative by default: no svg/html/php — anything a browser or the
	 * server might execute stays out unless a project opts in.
	 */
	public string $allowedFileExtensions = 'pdf,jpg,jpeg,png,doc,docx';

	public function getRecaptchaSiteKey(): string
	{
		return trim((string)App::parseEnv($this->recaptchaSiteKey));
	}

	public function getRecaptchaSecretKey(): string
	{
		return trim((string)App::parseEnv($this->recaptchaSecretKey));
	}

	public function getRecaptchaProjectId(): string
	{
		return trim((string)App::parseEnv($this->recaptchaProjectId));
	}

	public function getRecaptchaApiKey(): string
	{
		return trim((string)App::parseEnv($this->recaptchaApiKey));
	}

	public function getTurnstileSiteKey(): string
	{
		return trim((string)App::parseEnv($this->turnstileSiteKey));
	}

	public function getTurnstileSecretKey(): string
	{
		return trim((string)App::parseEnv($this->turnstileSecretKey));
	}

	public function getRecaptchaScoreThreshold(): float
	{
		return (float)$this->recaptchaThreshold;
	}

	public function getRecaptchaRejectThreshold(): float
	{
		return (float)$this->recaptchaRejectThreshold;
	}

	public function getMinSubmitSeconds(): int
	{
		return (int)$this->minSubmitSeconds;
	}

	public function getRetentionDays(): int
	{
		return (int)$this->retentionDays;
	}

	public function getThrottleCount(): int
	{
		return (int)$this->throttleCount;
	}

	public function getThrottleWindow(): int
	{
		return (int)$this->throttleWindow;
	}

	public function getUploadVolume(): string
	{
		return trim((string)App::parseEnv($this->uploadVolume));
	}

	public function getMaxUploadSize(): int
	{
		return (int)$this->maxUploadSize;
	}

	/**
	 * Allowed file extensions, lowercased, trimmed, without leading dots.
	 *
	 * @return string[]
	 */
	public function getAllowedFileExtensions(): array
	{
		return array_values(array_filter(array_map(
			fn($ext) => ltrim(mb_strtolower(trim($ext)), '.'),
			explode(',', $this->allowedFileExtensions),
		)));
	}

	/**
	 * Blocklist entries, lowercased and trimmed.
	 *
	 * @return string[] empty when not configured
	 */
	public function getBlocklist(): array
	{
		$blocklist = trim((string)App::parseEnv($this->blocklist));

		if ($blocklist === '') {
			return [];
		}

		return array_values(array_filter(array_map(
			fn($entry) => mb_strtolower(trim($entry)),
			explode(',', $blocklist),
		)));
	}

	/**
	 * Allowed token hostnames, lowercased and trimmed.
	 *
	 * @return string[] empty = fall back to the request's hostname
	 */
	public function getCaptchaAllowedHostnames(): array
	{
		$hostnames = trim((string)App::parseEnv($this->captchaAllowedHostnames));

		if ($hostnames === '') {
			return [];
		}

		return array_values(array_filter(array_map(
			fn($hostname) => mb_strtolower(trim($hostname)),
			explode(',', $hostnames),
		)));
	}

	protected function defineRules(): array
	{
		return [
			[['captchaProvider'], 'in', 'range' => [
				self::CAPTCHA_NONE,
				self::CAPTCHA_RECAPTCHA_V2,
				self::CAPTCHA_RECAPTCHA_V3,
				self::CAPTCHA_RECAPTCHA_ENTERPRISE,
				self::CAPTCHA_TURNSTILE,
			]],
			[['recaptchaThreshold', 'recaptchaRejectThreshold'], 'number', 'min' => 0, 'max' => 1],
			[['minSubmitSeconds', 'retentionDays', 'throttleCount', 'throttleWindow'], 'integer', 'min' => 0],
			[['maxUploadSize'], 'integer', 'min' => 1],
			[['honeypotName'], 'required'],
		];
	}
}
