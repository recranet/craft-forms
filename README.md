# Recranet Forms

Form builder plugin for Craft CMS 5. Built for the Elloro Craft boilerplate as a replacement for `craftcms/contact-form` + `contact-form-extensions`.

**Why it exists:** the stock stack silently marks every submission as spam when reCAPTCHA keys are misconfigured — visitors see success, mails never arrive, nobody notices. This plugin treats captcha *config errors* and *spam* as fundamentally different things.

## Features

- **Form builder in the CP** — drag & drop field cards (text, email, tel, textarea, select, checkbox) with per-field width (full/half for side-by-side columns), auto-suggested handles, and per-form notification recipients and subjects. Forms are content (database), so they're editable on production where `allowAdminChanges` is off.
- **Stored submissions** — every submission (including spam-flagged ones) is saved as an element, browsable per form in the CP, searchable, with statuses Sent/Spam/Failed and a per-form reference number (#1, #2, …). Values are keyed by field **uid** with a submit-time **snapshot** of the form definition, so renaming a field never orphans historical data. Mail send failures are recorded on the submission (status Failed) — nothing is ever lost. Identical double submits within 5 minutes are deduped.
- **Honest reCAPTCHA v3** — three verdicts instead of one:
  - *pass* → submission goes through
  - *spam* (low score, invalid token) → stored + flagged, visitor sees success (no bot tip-off), no email sent
  - *error* (bad keys, Google down) → **never treated as spam.** Fail-open (default): submission accepted + flagged with the reason, warning logged, note in the notification email. Fail-closed: visitor sees a real error.
- **Deploy health check** — `php craft recranet-forms/recaptcha/check` catches missing keys and Google connectivity problems, exits non-zero. Add it to the deploy flow. Note: Google validates tokens before secrets, so a wrong-but-present secret only surfaces at runtime — where `verify()` reports it as a config error (visible in the CP and the notification email), not as spam.
- **Honeypot** — hidden field, configurable name.
- **Notifications + confirmations** — HTML emails, reply-to set to the submitter, optional confirmation email. Templates overridable per project.
- **Multi-locale** — front-end strings translated for nl/en/de/fr/es/it.

## Installation

```bash
composer require recranet/craft-forms
php craft plugin/install recranet-forms
```

Set the keys in `.env`:

```
RECAPTCHA_SITE_KEY=...
RECAPTCHA_SECRET_KEY=...
```

## Usage

Render a form anywhere in Twig:

```twig
{{ craft.recranetForms.render('contact', {
	class: 'my-form',
	buttonLabel: 'button.send'|t,
	redirect: 'contact?submitted=true'
}) }}
```

### Custom form templates

Create `templates/recranet-forms/form.twig` in the project to fully own the markup. The template receives `form`, `options`, `formErrors`, `formContent`, `erroredFormHandle`. Required inputs:

```twig
{{ csrfInput() }}
{{ actionInput('recranet-forms/submissions/submit') }}
{{ hiddenInput('formHandle', form.handle|hash) }}
{# field inputs as fields[<handle>] #}
{{ craft.recranetForms.recaptchaTag() }}
```

Email templates are overridable at `templates/recranet-forms/_emails/notification.twig` and `confirmation.twig`.

## Settings

Plugin settings (CP → Settings → Recranet Forms, stored in project config):

| Setting | Default | Notes |
| --- | --- | --- |
| reCAPTCHA enabled | on | |
| Site/secret key | `$RECAPTCHA_SITE_KEY` / `$RECAPTCHA_SECRET_KEY` | env vars |
| Score threshold | 0.5 | below = spam |
| Fail open | on | what happens when verification *itself* errors |
| Honeypot name | `rf_website` | |
