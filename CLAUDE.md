# Recranet Forms

Craft CMS 5 form-builder plugin (`recranet/craft-forms`, handle `recranet-forms`). Lean by design: contact forms, quote requests, booking enquiries. Replaces `craftcms/contact-form` + `contact-form-extensions` in the Elloro Craft boilerplate.

## Architecture

- **Forms are DB content**, not project config — editable on production where `allowAdminChanges` is off. Table `recranetforms_forms` (columns: `name`, `handle`, `fields` JSON, `settings` JSON). Model `src/models/Form.php`, ActiveRecord `src/records/FormRecord.php`, CRUD in `src/services/Forms.php`.
- **Submissions are Craft elements** — `src/elements/Submission.php` + `src/elements/db/SubmissionQuery.php`, base row in `recranetforms_submissions` (`formId`, `formData` JSON keyed by field handle, `isSpam`, `spamReason`). Statuses: Valid/Spam.
- **reCAPTCHA v3 with three verdicts** (`src/services/Recaptcha.php` → `RecaptchaResult`): *pass*, *spam* (low score/bad token → stored + flagged, visitor sees success, no email), *error* (bad keys/Google down → **never spam**; fail-open default accepts + flags). Never collapse error into spam — that's the entire reason this plugin exists.
- **Field definitions** live as JSON rows on the form: `{label, handle, type, required, options, width}`. `Form::FIELD_TYPES` is the allowlist. `width` is `full|half` (Bootstrap col-12/col-md-6).
- **Rendering**: `craft.recranetForms.render(handle, options)` (`src/variables/RecranetFormsVariable.php`) → site override `templates/recranet-forms/form.twig` if it exists, else plugin default `src/templates/_render/form.twig`. Same pattern for email templates in `src/services/Notifications.php`.
- **CP builder**: `src/templates/forms/_edit.twig` — drag & drop field cards, inline JS + CSS, `rf-` class prefix, indexes reindexed by JS after reorder/delete.

## Adding a field type

Use the `add-field-type` skill — a new type touches five places; missing one fails silently.

## Conventions

- Tabs for indentation, comments explain what/why (matches the boilerplate).
- Front-end strings go through `Craft::t('recranet-forms', …)` and must be added to all six `src/translations/{nl,en,de,fr,es,it}/recranet-forms.php` files.
- CSS classes and data attributes use the `rf-` prefix.
- No hardcoded secrets ever — reCAPTCHA keys come from `.env` via `App::parseEnv` (`$RECAPTCHA_SITE_KEY` / `$RECAPTCHA_SECRET_KEY`).
- Public repo on GitHub (`recranet/craft-forms`) + Packagist. Releasing = tagging (see `release` skill).

## Testing locally

Develop inside a boilerplate clone via a composer path repository, or `composer require recranet/craft-forms` and copy changes back. Health check: `php craft recranet-forms/recaptcha/check`. E2E without a browser: POST to `recranet-forms/submissions/submit` with CSRF token — no reCAPTCHA token means the submission is stored as spam with reason "No reCAPTCHA token submitted" (that's correct behavior, not a bug).

## Roadmap

`docs/ROADMAP.md` holds the prioritized backlog distilled from a Formie/Gravity Forms/Freeform comparison (2026-08). Top structural items: field identity by UID instead of handle, validation on the element instead of the controller, conditional field logic. Read it before building new features — several current design choices (handle-keyed `formData`, controller validation) are flagged there as tech debt with agreed successors. **Ideas in that file come from proprietary plugins (Formie, Freeform, Gravity Forms) — never copy their source, templates, or class/CSS naming; clean-room implementations only.**
