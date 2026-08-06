# Recranet Forms

Form builder plugin for Craft CMS 5. Built for the Elloro Craft boilerplate as a replacement for `craftcms/contact-form` + `contact-form-extensions`.

**Why it exists:** the stock stack silently marks every submission as spam when reCAPTCHA keys are misconfigured — visitors see success, mails never arrive, nobody notices. This plugin treats captcha *config errors* and *spam* as fundamentally different things.

## Features

- **Form builder in the CP** — drag & drop field cards (text, email, tel, textarea, select, checkbox) with per-field width (full/half for side-by-side columns), auto-suggested handles, and per-form notification recipients and subjects. Forms are content (database), so they're editable on production where `allowAdminChanges` is off.
- **Stored submissions** — every submission (including spam-flagged ones) is saved as an element, browsable per form in the CP, searchable, with statuses Sent/Spam/Failed and a per-form reference number (#1, #2, …). Values are keyed by field **uid** with a submit-time **snapshot** of the form definition, so renaming a field never orphans historical data. Mail send failures are recorded on the submission (status Failed) — nothing is ever lost. Identical double submits within 5 minutes are deduped.
- **Multi-provider captcha** — Google reCAPTCHA v2/v3/Enterprise or Cloudflare Turnstile, with honest verdicts:
  - *pass* → submission goes through (v3/Enterprise score persisted on the submission)
  - *spam* (low score, invalid token) → stored + flagged, visitor sees success (no bot tip-off), no email sent. Scores below the **reject threshold** are definite bots: rejected outright, not stored.
  - *error* (bad keys, provider down) → **never treated as spam.** Fail-open (default): submission accepted + flagged with the reason, warning logged, note in the notification email. Fail-closed: visitor sees a real error.
- **Token binding** — v3/Enterprise tokens are checked against the per-form action and the hostname they were minted on, so a token farmed elsewhere cannot be replayed here.
- **Minimum fill time** — submissions arriving faster than a human could type (default 3s, hashed render timestamp) are rejected before any captcha call is made.
- **Sender blocklist** — for human-driven spam a captcha score cannot catch: match a full address, `@domain` suffix, local-part prefix or IP prefix. Matches are stored as reviewable spam.
- **Honeypot** — hidden field, toggleable, configurable name.
- **Storage switches + retention** — mail-only mode (`saveSubmissions` off), drop spam instead of storing it (`saveSpamSubmissions` off), and auto-delete stored submissions after N days (`retentionDays`, runs with Craft's GC or `php craft recranet-forms/gc/prune`). Match retention to the site's privacy statement.
- **Deploy health check** — `php craft recranet-forms/captcha/check` catches missing keys and provider connectivity problems, exits non-zero. Add it to the deploy flow. Note: Google validates tokens before secrets, so a wrong-but-present secret only surfaces at runtime — where it is reported as a config error (visible in the CP and the notification email), not as spam.
- **Email / SMTP test utility** — CP → Utilities → Email / SMTP test verifies the SMTP connection and sends a test mail, surfacing the full transport errors Craft's mailer swallows. Works with `allowAdminChanges` disabled.
- **CSV export** — the submissions index export includes "Submissions (expanded fields)": every form field becomes its own column.
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
{{ craft.recranetForms.captchaTag(form.handle) }}
```

`captchaTag()` renders the hashed timestamp field (submit-timing check) plus the configured captcha widget, its token bound to the given action name — give every form its own. The old `recaptchaTag()` still works as a deprecated alias.

Email templates are overridable at `templates/recranet-forms/_emails/notification.twig` and `confirmation.twig`; values come from `submission.values` (submit-time snapshot) or `submission.value('handle')`.

## Placing a form in page content

Besides the Twig call, forms can be dropped into content: create a field of type **Form** (Settings → Fields), add it to an entry type / Matrix block / CKEditor entry, and pick the form. The field stores the form's **uid**, so renaming its handle never breaks the reference.

```twig
{{ entry.contactForm }}                      {# renders the picked form #}
{{ entry.contactForm.form.name }}            {# the Form model #}
{{ entry.contactForm.render({ class: 'x' }) }}
```

An empty field renders nothing; so does a field pointing at a form that has since been deleted.

## Previewing

The form edit screen has preview panels: **Fields → Preview form** renders the front-end template, and the Notification/Confirmation tabs preview the emails with sample values (subject included, merge tags resolved). Previews go through the same template resolution as a real render or send, so "Default template" is something you can look at before overriding it. Nothing is sent or stored.

## Settings

Plugin settings (CP → Settings → Recranet Forms, stored in project config):

| Setting | Default | Notes |
| --- | --- | --- |
| Captcha provider | reCAPTCHA v3 | none / v2 / v3 / Enterprise / Turnstile |
| Site/secret key | `$RECAPTCHA_SITE_KEY` / `$RECAPTCHA_SECRET_KEY` | env vars |
| Score threshold | 0.5 | below = spam (stored, reviewable) |
| Reject threshold | 0.3 | below = definite bot (rejected, not stored) |
| Fail open | on | what happens when verification *itself* errors |
| Verify token hostname | on | rejects tokens minted on other hostnames |
| Honeypot | on, `rf_website` | |
| Minimum submit time | 3s | 0 disables |
| Sender blocklist | empty | address / @domain / local-part / IP prefix |
| Save submissions | on | off = mail-only mode |
| Save spam submissions | on | off = flagged spam is dropped |
| Retention (days) | 0 (keep forever) | prunes with Craft GC |

## Multi-site / translations

Field labels, placeholders, descriptions, option labels and custom validation messages entered in the builder run through Craft's static translations (`site` category) when rendered. To localize a form per site, add the editor-entered strings as keys to `translations/{locale}/site.php`:

```php
'Naam' => 'Name',
'Bericht' => 'Message',
'Vraag, Klacht, Anders' => null, // option VALUES stay in the source language
'Vraag' => 'Question',
```

Option **values** stored with submissions stay in the source language (option labels translate in the UI); validation always checks against the source values. The plugin's own strings (validation messages, buttons) ship translated for nl/en/de/fr/es/it in `src/translations/`.
