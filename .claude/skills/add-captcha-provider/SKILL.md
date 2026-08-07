---
name: add-captcha-provider
description: Add a captcha provider to Recranet Forms — provider class, settings, pipeline wiring, health check
---

# Add a captcha provider

A provider touches five places. Read `src/captchas/CLAUDE.md` first — the config-error-vs-spam split is the plugin's core promise, and getting it wrong silently flags real visitors as spam.

## 1. Provider class — `src/captchas/<Name>.php`

Extend `BaseCaptcha` (use `Turnstile.php` as the minimal example, `RecaptchaV3.php` for a scored/action-bound one):

- `getName()`, `getResponseParamName()` (the POST param the widget injects)
- `verify()`: throw `CaptchaError` when the secret key is empty; call `siteVerify()` (throws on transport/malformed response); define a `CONFIG_ERROR_CODES` const and call `assertNoConfigError()` so key/config rejections throw instead of becoming spam verdicts; return `CaptchaVerification` — pass `score` only if the provider scores (else null → failures stay reviewable, never auto-reject), and always pass `hostname` through when reported (hostname binding needs it)
- `render()`: fail **soft** on a missing site key — `Plugin::error()` + HTML comment, never throw
- Action-bound provider (like v3/Enterprise): override `supportsAction(): true`, mint the token with `resolveAction($action)`, include `renderActionField()` in the render output, and send the expected action along in `verify()`

## 2. Settings — `src/models/Settings.php`

- `CAPTCHA_<NAME>` const + add it to the `captchaProvider` 'in' rule
- Key properties + `App::parseEnv`-based getters (follow `getTurnstileSiteKey()`). Secrets default to a `$ENV_VAR` reference when the boilerplate ships one — never a literal key (public repo)

## 3. Pipeline wiring — `src/services/SpamService.php`

Add the `match` arm in `getCaptcha()`. That's the only registry there is.

## 4. Settings screen — `src/templates/settings.twig`

Provider option in the dropdown + key fields in the captcha pane (follow the Turnstile fields: `autosuggestField` with `suggestEnvVars: true`). Wrap the new key fields in a `<div data-rfs-show="<provider-value>">` block — the pane shows only the chosen provider's fields — and add the provider value to the existing `data-rfs-show` lists it belongs to (Keys section, Failure handling; Scoring only if it scores). Inputs stay in the DOM, so hidden values still post.

## 5. Translations

New CP labels/instructions in all six `src/translations/*/recranet-forms.php`.

## Verify

```bash
find src -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v "No syntax"
php craft recranet-forms/captcha/check
```

Then E2E: select the provider in the CP, load a form (widget renders), submit without a token (must be stored as spam with a "token missing"-style CaptchaError reason under fail-open — not silently dropped), submit with the widget (must pass). Break the secret key on purpose: the result must be a logged config error + flagged-but-accepted submission (fail-open), **never** plain "spam".
