# Elloro Forms

Form builder plugin for Craft CMS 5. Built for the Elloro Craft boilerplate as a replacement for `craftcms/contact-form` + `contact-form-extensions`.

**Why it exists:** the stock stack silently marks every submission as spam when reCAPTCHA keys are misconfigured — visitors see success, mails never arrive, nobody notices. This plugin treats captcha *config errors* and *spam* as fundamentally different things.

## Features

- **Form builder in the CP** — editors manage forms as an ordered field list (text, email, tel, textarea, select, checkbox), with per-form notification recipients and subjects. Forms are content (database), so they're editable on production where `allowAdminChanges` is off.
- **Stored submissions** — every submission (including spam-flagged ones) is saved as an element, browsable per form in the CP, searchable, with statuses Valid/Spam.
- **Honest reCAPTCHA v3** — three verdicts instead of one:
  - *pass* → submission goes through
  - *spam* (low score, invalid token) → stored + flagged, visitor sees success (no bot tip-off), no email sent
  - *error* (bad keys, Google down) → **never treated as spam.** Fail-open (default): submission accepted + flagged with the reason, warning logged, note in the notification email. Fail-closed: visitor sees a real error.
- **Deploy health check** — `php craft elloro-forms/recaptcha/check` validates the secret key against Google and exits non-zero on config problems. Add it to the deploy flow.
- **Honeypot** — hidden field, configurable name.
- **Notifications + confirmations** — HTML emails, reply-to set to the submitter, optional confirmation email. Templates overridable per project.
- **Multi-locale** — front-end strings translated for nl/en/de/fr/es/it.

## Installation

Private package. In the project's `composer.json`:

```json
"repositories": [
	{ "type": "vcs", "url": "git@github.com:elloro/craft-forms.git" }
]
```

```bash
composer require elloro/craft-forms
php craft plugin/install elloro-forms
```

Set the keys in `.env`:

```
RECAPTCHA_SITE_KEY=...
RECAPTCHA_SECRET_KEY=...
```

## Usage

Render a form anywhere in Twig:

```twig
{{ craft.elloroForms.render('contact', {
	class: 'my-form',
	buttonLabel: 'button.send'|t,
	redirect: 'contact?submitted=true'
}) }}
```

### Custom form templates

Create `templates/elloro-forms/form.twig` in the project to fully own the markup. The template receives `form`, `options`, `formErrors`, `formContent`, `erroredFormHandle`. Required inputs:

```twig
{{ csrfInput() }}
{{ actionInput('elloro-forms/submissions/submit') }}
{{ hiddenInput('formHandle', form.handle|hash) }}
{# field inputs as fields[<handle>] #}
{{ craft.elloroForms.recaptchaTag() }}
```

Email templates are overridable at `templates/elloro-forms/_emails/notification.twig` and `confirmation.twig`.

## Settings

Plugin settings (CP → Settings → Elloro Forms, stored in project config):

| Setting | Default | Notes |
| --- | --- | --- |
| reCAPTCHA enabled | on | |
| Site/secret key | `$RECAPTCHA_SITE_KEY` / `$RECAPTCHA_SECRET_KEY` | env vars |
| Score threshold | 0.5 | below = spam |
| Fail open | on | what happens when verification *itself* errors |
| Honeypot name | `ef_website` | |
