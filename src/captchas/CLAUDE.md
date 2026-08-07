# Captcha providers

Providers implement `CaptchaInterface`; HTTP-based ones extend `BaseCaptcha`. Instantiated per request in `SpamService::getCaptcha()` with `Settings` injected — no registry, adding one means a new `match` arm there (see the `add-captcha-provider` skill).

## The iron rule

A provider failure is either the **visitor's** problem (invalid/expired token, low score → a `CaptchaVerification` with `success: false`) or **our** problem (missing/bad keys, endpoint down, malformed response → throw `CaptchaError`). Never return a failed verification for a config problem: SpamService turns verifications into spam verdicts, and a misconfigured key silently flagging every real submission as spam is the exact failure this plugin was built to avoid. Each provider keeps a `CONFIG_ERROR_CODES` const and calls `assertNoConfigError()` to make the split explicit.

## Contract

- `getName()` — for logs and spam reasons.
- `getResponseParamName()` — POST param the widget writes the token into (e.g. `g-recaptcha-response`, `cf-turnstile-response`).
- `supportsAction()` — true only for providers that mint tokens bound to an action name (v3, Enterprise). Those must render the hashed action field (`BaseCaptcha::renderActionField()`) and pass `$expectedAction` in the verification request; SpamService compares the reported action server-side.
- `verify(token, ip, expectedAction)` — returns `CaptchaVerification(success, score, errorCodes, action, hostname)`. `score: null` = unscored provider (v2 checkbox, Turnstile); SpamService then treats failures as reviewable spam, never auto-reject. Always pass `hostname` through when the provider reports it — token hostname binding depends on it.
- `render(action)` — widget markup + scripts. Must fail **soft** when the site key is missing: log via `Plugin::error()` and return an HTML comment, never throw (a broken form page punishes visitors for our config).

## BaseCaptcha helpers

- `siteVerify(url, params)` — Guzzle POST, 5s timeout, throws `CaptchaError` on transport errors or non-JSON.
- `assertNoConfigError(errorCodes, configErrorCodes)` — throws for the config subset.
- `resolveAction(action)` — Google only accepts `[A-Za-z0-9/_]`; anything else logs an error and falls back to `DEFAULT_ACTION` ('submit') instead of failing verification later with a confusing mismatch.

## Health check caveat

`php craft recranet-forms/captcha/check` verifies a dummy token: proves keys are present and the endpoint answers. Google validates the token BEFORE the secret, so a wrong-but-present secret looks healthy there — that case only surfaces at runtime as a `CaptchaError` (visible in CP + logs), never as spam.
