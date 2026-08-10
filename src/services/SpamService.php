<?php

namespace recranet\forms\services;

use Craft;
use recranet\forms\captchas\BaseCaptcha;
use recranet\forms\captchas\CaptchaError;
use recranet\forms\captchas\CaptchaInterface;
use recranet\forms\captchas\CaptchaVerification;
use recranet\forms\captchas\RecaptchaEnterprise;
use recranet\forms\captchas\RecaptchaV2;
use recranet\forms\captchas\RecaptchaV3;
use recranet\forms\captchas\Turnstile;
use recranet\forms\elements\Submission;
use recranet\forms\FormFields;
use recranet\forms\models\Form;
use recranet\forms\models\Settings;
use recranet\forms\models\SpamVerdict;
use recranet\forms\Plugin;
use yii\base\Component;
use yii\web\Request;

/**
 * Runs the spam pipeline: blocklist, honeypot, throttle, submit timing, the
 * one-time submit token, then the configured captcha (including the
 * action/hostname the token was minted with).
 *
 * The cheap local checks run first so a bot never costs us a captcha
 * verification — which matters on reCAPTCHA Enterprise, where assessments are
 * metered.
 *
 * Only genuine visitor failures produce a spam verdict. Configuration and
 * availability problems throw a CaptchaError so they surface as real errors.
 */
class SpamService extends Component
{
	/**
	 * A form rendered longer ago than this was almost certainly served from a
	 * cache, so an implausible age is logged rather than treated as spam.
	 */
	private const STALE_FORM_SECONDS = 604800;

	/**
	 * How long a consumed one-time token is remembered. A replay after this
	 * window slips through — 24 hours covers the realistic capture-and-replay
	 * scenario without keeping cache entries around forever.
	 */
	private const TOKEN_REPLAY_TTL_SECONDS = 86400;

	/**
	 * The active captcha provider, or null when none is configured.
	 */
	public function getCaptcha(): ?CaptchaInterface
	{
		$settings = Plugin::getInstance()->getSettings();

		return match ($settings->captchaProvider) {
			Settings::CAPTCHA_RECAPTCHA_V2 => new RecaptchaV2($settings),
			Settings::CAPTCHA_RECAPTCHA_V3 => new RecaptchaV3($settings),
			Settings::CAPTCHA_RECAPTCHA_ENTERPRISE => new RecaptchaEnterprise($settings),
			Settings::CAPTCHA_TURNSTILE => new Turnstile($settings),
			default => null,
		};
	}

	/**
	 * Classify the current request.
	 *
	 * @throws CaptchaError when the captcha cannot be verified due to
	 * configuration or availability problems
	 */
	public function check(Request $request, Form $form, Submission $submission): SpamVerdict
	{
		$settings = Plugin::getInstance()->getSettings();

		// Blocklist first: it is an explicit decision about a known sender, so
		// it should win over every heuristic below
		$blocklistVerdict = $this->checkBlocklist($request, $settings, $form, $submission);

		if ($blocklistVerdict !== null) {
			return $blocklistVerdict;
		}

		// Honeypot: a filled-in hidden field is definitely a bot — reject
		// outright, there is nothing worth reviewing
		if ($settings->honeypotEnabled && trim((string)$request->getBodyParam($settings->honeypotName)) !== '') {
			return new SpamVerdict(isSpam: true, reason: 'honeypot', reject: true);
		}

		// Throttle right after the honeypot: it's a cheap local cache lookup,
		// and rate-limiting a hammering bot here saves every check below
		$throttleVerdict = $this->checkThrottle($request, $settings, $form);

		if ($throttleVerdict !== null) {
			return $throttleVerdict;
		}

		$timingVerdict = $this->checkTiming($request, $settings);

		if ($timingVerdict !== null) {
			return $timingVerdict;
		}

		// Last of the local checks: consuming the token writes to the cache,
		// so the free read-only checks above go first
		$tokenVerdict = $this->checkOneTimeToken($request, $settings);

		if ($tokenVerdict !== null) {
			return $tokenVerdict;
		}

		$captcha = $this->getCaptcha();

		if ($captcha === null) {
			return new SpamVerdict();
		}

		$token = trim((string)$request->getBodyParam($captcha->getResponseParamName()));

		// A missing token means the widget never produced one — typically a
		// blocked script, a broken domain allowlist or a direct bot POST. This
		// throws (config error path) so fail-open stores it visibly and
		// fail-closed shows the visitor an error; nothing is lost either way.
		if ($token === '') {
			throw new CaptchaError(sprintf(
				'%s token missing from the request — the widget did not run (check the domain allowlist and site key, or the visitor blocks the script)',
				$captcha->getName(),
			));
		}

		// Forms rendered before the hashed action field existed minted the
		// default action, so falling back to it keeps those templates working
		// while still binding the token to an action
		$expectedAction = $captcha->supportsAction()
			? ($this->expectedAction() ?? BaseCaptcha::DEFAULT_ACTION)
			: null;

		$verification = $captcha->verify($token, $request->getUserIP(), $expectedAction);

		// A token is only meaningful for the form and host it was minted for.
		// Both checks store rather than reject: a systematic mismatch is far
		// more likely to be our misconfiguration than an attack, and storing
		// keeps it visible in the control panel instead of silently dropping
		// every submission the site receives.
		$bindingVerdict = $this->checkTokenBinding($request, $settings, $verification, $expectedAction);

		if ($bindingVerdict !== null) {
			return $bindingVerdict;
		}

		if ($verification->success) {
			return new SpamVerdict(isSpam: false, score: $verification->score);
		}

		// Scored spam is split in two: very low scores are definite bots and
		// are rejected without being stored; the gray zone between the reject
		// and score thresholds is stored as spam for human review
		if ($verification->score !== null) {
			$reject = $verification->score < $settings->getRecaptchaRejectThreshold();

			return new SpamVerdict(
				isSpam: true,
				score: $verification->score,
				reason: sprintf('captcha-score (%s below threshold)', $verification->score),
				reject: $reject,
			);
		}

		// Unscored failures (invalid/expired token) are ambiguous — a slow
		// legit visitor can hit these — so they stay reviewable
		return new SpamVerdict(
			isSpam: true,
			reason: sprintf('captcha-failed (%s)', implode(', ', $verification->errorCodes) ?: 'invalid token'),
		);
	}

	/**
	 * Match the sender against the blocklist.
	 *
	 * A captcha score says nothing when a human is doing the spamming — those
	 * submissions score 1.0 — so no threshold can stop a persistent sender.
	 * This is the escape hatch. Entry shapes:
	 *
	 *   full address:   someone@example.com
	 *   domain suffix:  @spamdomain.ru
	 *   local prefix:   someone          (start of the part before the @, so
	 *                                    numbered variants all match)
	 *   IP prefix:      2001:1c00:6703:  or  198.51.100.
	 *
	 * Hits are stored rather than rejected outright: prefix matching is coarse
	 * and can catch an unrelated sender, so the decision stays reviewable.
	 */
	private function checkBlocklist(Request $request, Settings $settings, Form $form, Submission $submission): ?SpamVerdict
	{
		// Union of the config list (project config, deploy-managed) and the
		// stored list an editor can add to on production — see the Blocklist
		// service for why there are two
		$blocklist = Plugin::getInstance()->blocklist->allPatterns();

		if ($blocklist === []) {
			return null;
		}

		// The sender address is the form's first email field, when it has one
		$emailHandle = $form->getEmailFieldHandle();
		$email = mb_strtolower(trim((string)($emailHandle ? $submission->value($emailHandle) : '')));
		$localPart = explode('@', $email)[0];
		$ip = mb_strtolower((string)$request->getUserIP());

		foreach ($blocklist as $entry) {
			if (str_contains($entry, '@')) {
				// full address, or a domain suffix like "@spamdomain.ru"
				$matches = $email !== '' && ($entry === $email || str_ends_with($email, $entry));
			} elseif (preg_match('/^(\d[\d.]*|[0-9a-f:][0-9a-f.:]*:)$/', $entry)) {
				// IPv4 prefix (digits and dots) or IPv6 prefix (ends in a colon)
				$matches = $ip !== '' && str_starts_with($ip, $entry);
			} else {
				// local-part prefix: "someone" catches every numbered variant
				$matches = $localPart !== '' && str_starts_with($localPart, $entry);
			}

			if ($matches) {
				return new SpamVerdict(
					isSpam: true,
					reason: sprintf('blocklist (entry "%s")', $entry),
				);
			}
		}

		return null;
	}

	/**
	 * Rate-limit submissions per IP + form within a rolling window.
	 *
	 * A real visitor doesn't submit the same form 6 times a minute — a bot
	 * hammering the endpoint does — so exceeding the limit is a reject, not
	 * reviewable spam: storing hundreds of hammered submits would flood the
	 * review list with exactly the noise the throttle exists to stop.
	 *
	 * The counter lives in Craft's cache with the window as TTL, and is
	 * incremented BEFORE it is checked so parallel submits all count (two
	 * simultaneous posts each see the other's increment on the next hit).
	 * Every hit rewrites the TTL, so a bot that keeps hammering stays
	 * throttled until it backs off for a full window.
	 */
	private function checkThrottle(Request $request, Settings $settings, Form $form): ?SpamVerdict
	{
		$count = $settings->getThrottleCount();
		$window = $settings->getThrottleWindow();

		// 0 for either value disables the check
		if ($count <= 0 || $window <= 0) {
			return null;
		}

		$ip = $request->getUserIP();

		// No IP to key on (console request, exotic proxy setup): nothing to
		// throttle against, so don't punish the visitor for it
		if (!$ip) {
			return null;
		}

		$cache = Craft::$app->getCache();
		$key = sprintf('recranet-forms:throttle:%s:%s', $form->handle, $ip);

		// Increment first, check second — see the docblock above
		$submits = (int)$cache->get($key) + 1;
		$cache->set($key, $submits, $window);

		if ($submits > $count) {
			return new SpamVerdict(
				isSpam: true,
				reason: sprintf('throttled (%d submits in %ds)', $submits, $window),
				reject: true,
			);
		}

		return null;
	}

	/**
	 * Enforce the one-time submit token: each render mints a fresh signed
	 * nonce, the first submit consumes it, and a repeat within the replay
	 * window is flagged. Consuming uses the cache's add() — atomic on
	 * backends that support it — so two racing submits cannot both pass.
	 *
	 * A replay is stored as reviewable spam rather than rejected: the guard
	 * is coarse (a full-page cache hands the same token to every visitor),
	 * so what it catches stays visible for human review. A token that fails
	 * its hash check is different — that is a tampered request, rejected
	 * like a forged timestamp. Requests without the field (custom templates,
	 * pages cached before the setting was turned on) are not checked.
	 */
	private function checkOneTimeToken(Request $request, Settings $settings): ?SpamVerdict
	{
		if (!$settings->oneTimeSubmitTokens) {
			return null;
		}

		$hashed = trim((string)$request->getBodyParam(FormFields::SUBMIT_TOKEN));

		if ($hashed === '') {
			return null;
		}

		// validateData() rather than getValidatedBodyParam(): a tampered
		// field should be a spam verdict, not a 400 that tells a bot which
		// check it tripped over
		$token = Craft::$app->getSecurity()->validateData($hashed);

		if ($token === false) {
			return new SpamVerdict(isSpam: true, reason: 'submit-token-invalid', reject: true);
		}

		$key = sprintf('recranet-forms:submit-token:%s', $token);

		if (!Craft::$app->getCache()->add($key, 1, self::TOKEN_REPLAY_TTL_SECONDS)) {
			return new SpamVerdict(isSpam: true, reason: 'submit-token-replayed (token was already used)');
		}

		return null;
	}

	/**
	 * Reject submissions that arrived too fast to have been filled in by a
	 * human, based on the hashed timestamp the form was rendered with.
	 *
	 * Forms that don't render the field are simply not checked, so a custom
	 * template without the timestamp keeps working.
	 */
	private function checkTiming(Request $request, Settings $settings): ?SpamVerdict
	{
		if ($settings->getMinSubmitSeconds() <= 0) {
			return null;
		}

		$hashed = trim((string)$request->getBodyParam(FormFields::TIMESTAMP));

		if ($hashed === '') {
			return null;
		}

		// validateData() rather than getValidatedBodyParam(): a tampered field
		// should be a spam verdict, not a 400 that tells a bot which check it
		// tripped over
		$timestamp = Craft::$app->getSecurity()->validateData($hashed);

		if ($timestamp === false) {
			return new SpamVerdict(isSpam: true, reason: 'timestamp-invalid', reject: true);
		}

		$elapsed = time() - (int)$timestamp;

		if ($elapsed < $settings->getMinSubmitSeconds()) {
			return new SpamVerdict(
				isSpam: true,
				reason: sprintf('too-fast (submitted %ds after the form was rendered)', $elapsed),
				reject: true,
			);
		}

		// A page served from cache carries an old timestamp — worth knowing
		// about, never worth rejecting a real visitor over
		if ($elapsed > self::STALE_FORM_SECONDS) {
			Plugin::info(sprintf('Form was rendered %d days before it was submitted (cached page?)', (int)floor($elapsed / 86400)));
		}

		return null;
	}

	/**
	 * Verify that the token belongs to this form and this site.
	 *
	 * Without these checks a v3/Enterprise token minted anywhere the site key
	 * is in use — a lower-value form on the same site, or another domain on the
	 * key's allowlist — passes verification here.
	 */
	private function checkTokenBinding(
		Request $request,
		Settings $settings,
		CaptchaVerification $verification,
		?string $expectedAction,
	): ?SpamVerdict {
		// Providers that don't report an action (v2 checkbox, Turnstile), and
		// forms rendered before this field existed, are not checked
		if ($expectedAction !== null && $verification->action !== null && $verification->action !== $expectedAction) {
			return new SpamVerdict(
				isSpam: true,
				score: $verification->score,
				reason: sprintf(
					'captcha-action-mismatch (token minted for "%s", form expected "%s")',
					$verification->action,
					$expectedAction,
				),
			);
		}

		if (!$settings->verifyCaptchaHostname || $verification->hostname === null) {
			return null;
		}

		$tokenHostname = strtolower($verification->hostname);
		$allowed = $settings->getCaptchaAllowedHostnames() ?: [strtolower((string)$request->getHostName())];
		$allowed = array_values(array_filter($allowed));

		// No hostname to compare against (an unusual request context): there is
		// nothing to verify, so don't punish the visitor for it
		if ($allowed === []) {
			return null;
		}

		foreach ($allowed as $hostname) {
			if (hash_equals($hostname, $tokenHostname)) {
				return null;
			}
		}

		return new SpamVerdict(
			isSpam: true,
			score: $verification->score,
			reason: sprintf(
				'captcha-hostname-mismatch (token minted on "%s", expected %s)',
				$tokenHostname,
				implode(' or ', array_map(fn($hostname) => '"' . $hostname . '"', $allowed)),
			),
		);
	}

	/**
	 * The action the form was rendered with, read from its hashed field. Null
	 * when the field is absent (an older template) or fails its hash check.
	 */
	private function expectedAction(): ?string
	{
		$hashed = trim((string)Craft::$app->getRequest()->getBodyParam(FormFields::CAPTCHA_ACTION));

		if ($hashed === '') {
			return null;
		}

		$action = Craft::$app->getSecurity()->validateData($hashed);

		return $action === false ? null : (string)$action;
	}
}
