---
name: add-field-type
description: Add a new field type to Recranet Forms — every place that must change, in order
---

# Add a field type

A field type touches six places. Missing one fails silently (type renders as a bare text input, or values vanish from emails). Work through all of them.

Current types: see `Form::FIELD_TYPES` in `src/models/Form.php`. Layout-only types (no input, no stored value — heading, paragraph) are additionally listed in `Form::LAYOUT_TYPES`; everything that touches values checks that const, so a new layout type is mostly a render branch.

## 1. Allowlist — `src/models/Form.php`

Add the type string to `Form::FIELD_TYPES`. Validation rejects unknown types, so nothing works until this is done. A layout-only type also goes into `Form::LAYOUT_TYPES` (that single const gates validation, formData storage, `getValues()`, emails, CP view, export and search keywords).

## 2. Front-end render — `src/templates/_render/form.twig`

Add a branch to the type conditional (`field.type == '...'`). Follow the existing pattern:

- Bootstrap 5 classes (`form-control` / `form-select` / `form-check-input`), `is-invalid` when `fieldErrors`
- `name="fields[{{ field.handle }}]"`, `id="{{ inputId }}"` — multi-value types post as `fields[{{ field.handle }}][]` (see `checkboxes`)
- Repopulate from `value` after a failed submit
- `required` attribute when `field.required` (but not on multi-checkbox groups — the browser would demand every box)
- Types with choices read comma-separated `field.options` (see the `select`/`radio`/`checkboxes` branches)
- a11y: label tied via `for` (or fieldset+legend for groups), `aria-describedby` via `describedBy`, `aria-invalid`, visible error text with `errorId`

If the type's value shape is new (array, boolean), also extend the **conditional-rules JS** in the same file (`fieldValue()`/`rulePasses()`) AND its PHP mirror `src/rules/RuleEvaluator.php` — the two must stay in strict parity (see the array branch added for `checkboxes`).

Remember: projects can override this template entirely (`templates/recranet-forms/form.twig`), so keep the default markup accessible.

## 3. Normalization + validation — `src/elements/Submission.php`

Validation lives on the element, not the controller:

- `applyPost()` normalizes the posted value per type (bool for checkbox/consent, string array for checkboxes, sanitized string for hidden, `UploadedFile` capture for file). Layout types are skipped entirely.
- `validateFormData()` has the per-type rules (see the `email`, `select`/`radio`, `number`, `date`, `url`, `checkboxes` and file branches). Never trust the posted value — a choice type must reject values outside `field.options`. Error messages go through `Craft::t('recranet-forms', …)` → step 6.
- File-like types: validate in `validateFormData()`, create side effects (assets) in `beforeSave()` — never earlier, so invalid/spam/duplicate submissions leave nothing behind — and clean up in `afterDelete()` (hard delete only).

## 4. Builder card — `src/templates/forms/_edit.twig`

The type appears automatically in the "Add a field" menu (it loops `fieldTypes`). Check whether the card needs more than the defaults:

- Types with choices must show the **options** input (see how `select` toggles it)
- New per-field settings mean extending the field JSON shape `{uid, handle, label, type, required, options, width, placeholder, description, adminLabel, defaultValue, errorMessage, conditions}` — update the card, the JS template (`#rf-field-template`), and `Form::validateFields`

## 5. Value formatting — emails + CP + export

All of these consume `submission.values` (which already excludes layout types); each needs a branch if the value isn't a plain string:

- `src/templates/_emails/notification.twig` — owner mail (checkbox/consent → Yes/No, arrays → joined with ', ', file → filename + CP link)
- `src/templates/_emails/confirmation.twig` — submitter mail (same, but translated Yes/No and never a CP link)
- The boilerplate's overrides `templates/recranet-forms/_emails/*.twig` need the same branches
- `src/templates/submissions/view.twig` — CP detail view
- `src/elements/exporters/ExpandedSubmissions.php` — CSV/JSON export
- `Submission::getPreviewText()` / `getContentKeywords()` — index preview and CP search (already handle arrays)

## 6. Translations

New visitor-facing strings: add to **all six** `src/translations/*/recranet-forms.php` (nl/en/de/fr/es/it).

## Plugin settings (only for types that need them)

File uploads read `uploadVolume`, `maxUploadSize` and `allowedFileExtensions` from `src/models/Settings.php`, edited in the Storage pane of `src/templates/settings.twig`. A new type needing global config follows that pattern.

## Verify

```bash
find src -name '*.php' -print0 | xargs -0 -n1 php -l
```

Then in a project with the plugin: create a form with the new field in the CP, submit once via the front end, and check (a) validation rejects bad input, (b) the value lands in `formData`, (c) the notification email shows it.
