# Templates

CP templates (builder, indexes, settings) and the overridable defaults for the front end and emails. All visitor/editor strings go through `|t('recranet-forms')` → add to all six `src/translations/*/recranet-forms.php`.

## Map

- `forms/_edit.twig` — the builder (biggest file; see below)
- `forms/index.twig`, `forms/_import.twig` — form list, JSON import screen
- `submissions/index.twig`, `submissions/view.twig` — element index + detail view
- `settings.twig` — plugin settings, five tab panes (spam protection / emails / storage & retention / uploads / payments; `rfs-` prefix, all inputs stay in the DOM so one form posts everything). The spam pane keeps provider + keys in view (provider-dependent blocks via `data-rfs-show="<provider values>"` — hidden inputs still post, so switching providers never wipes stored keys); everything with sane defaults sits in collapsed `<details class="rfs-group">` sections (Scoring / Failure handling / Automatic bot checks / Blocklist / Spam behaviour) that auto-open on validation errors. The emails pane points editors to the per-form mail settings. Craft namespaces the form: the select is `settings[captchaProvider]`
- `_render/form.twig` — default front-end form; `_render/submission.twig` — tokenized self-service view
- `_emails/notification.twig` (owner), `_emails/confirmation.twig` (submitter)
- `_preview.twig` — iframe wrapper for the split-view previews; bundles a small neutral stylesheet for the Bootstrap classes `_render/form.twig` emits (no CDN — works offline; extend it when the render template gains classes). Preview shows structure/wording, not site design
- `utilities/email-test.twig` — SMTP test utility

## Override resolution (keep intact everywhere)

Front-end form: site `templates/recranet-forms/form.twig` → plugin `_render/form.twig`. Submission view: `recranet-forms/submission.twig` → `_render/submission.twig`. Emails: per-form template override (dropdown in the form's mail settings, scanned from `recranet-forms/_emails/` and `_emails/`) → site `templates/recranet-forms/_emails/*.twig` → plugin default. A broken per-form path logs and falls through — mail always goes out.

## `_render/form.twig` contract

Variables: `form` (translations already applied), `options` (class, buttonLabel, redirect), `formErrors` (by handle), `formContent` (values by handle), `erroredFormHandle`. Conventions:

- Bootstrap 5 classes, `is-invalid` on errors, a11y wiring (`for`/fieldset+legend, `aria-describedby`, `aria-invalid`, visible error text)
- Inputs post as `fields[<handle>]` (multi-value: `fields[<handle>][]`); repopulate from `value` after failed submit
- `craft.recranetForms.captchaTag(form.handle)` inside the `<form>` — timestamp field + captcha widget, action = form handle
- Hidden fields may seed from query params — sanitized (scalar, 255 cap)
- The inline `{% js %}` block implements conditional visibility and MUST stay in parity with `src/rules/RuleEvaluator.php` (hidden attribute + disabled inputs + `required` lifted; malformed rules fail open)
- Privacy agreement: with `privacyPolicyUrl` set, forms WITHOUT a consent field render a required `rfPrivacyConsent` checkbox (server half in `SubmissionsController::actionSubmit` — keep both); forms WITH one only get the link. Custom form templates must render the checkbox themselves or the submit will fail

Projects override this template wholesale — keep the default accessible and framework-light.

## Email template contract

Variables: `form`, `submission`, `intro` (notification) and `bodyText` (confirmation) — the latter two already merge-tag-rendered. Iterate `submission.values` (snapshot-ordered rows, layout types pre-filtered); branch per type: checkbox/consent → Yes/No (translated in the confirmation), arrays → joined `', '`, file → filename (+ CP link in the notification only — never in the confirmation). Confirmation may include `{selfServiceUrl}`.

## Builder (`forms/_edit.twig`)

Inline CSS + JS, `rf-` prefix on classes/ids/data attributes. Key parts:

- `fieldCard` macro renders a card per field row; `#rf-field-template` is the `<template>` JS clones for new cards — **change both** when the card changes
- ONE native HTML5 drag system for palette tiles AND existing cards (cards drag from their `.rf-field__move` handle): middle of a card = row insert (drop marker line), left/right quarter = side-by-side drop that sets **both** cards to half width, with a slide-aside preview on hover. The palette popover fades out during a drag. After every structural change `fixLonelyHalves()` returns a half card without a half neighbour to full — never on page load. Keyboard fallback: click-to-add + Alt+arrows (HTML5 DnD does nothing on touch; click path covers it). Clean-room GF-inspired behavior — never copy their code/naming
- Every card carries a hidden `uid` input — the field's stable identity; it must survive reorder, tab switches and re-render (submissions key by uid)
- Card tabs: General / Appearance / Advanced (`data-rf-tab`); conditions builder serializes to JSON in a hidden `conditions` input (decoded defensively in `FormsController::normalizeFieldRows()`)
- JS reindexes `fields[N][…]` names after reorder/delete; `#rf-known-fields` JSON feeds the conditions rule dropdowns
- Translation mode (`isTranslation`, non-primary site via `?site=<handle>`): structure is read-only, only `translations[<key>]` inputs post — key shapes in `FormTranslations`
- Field JSON shape: `{uid, handle, label, type, required, options, width, placeholder, description, adminLabel, defaultValue, errorMessage, conditions}` — extending it touches the card, the JS template, `normalizeFieldRows()` and `Form::validateFields()`
