---
name: add-field-type
description: Add a new field type to Recranet Forms — every place that must change, in order
---

# Add a field type

A field type touches five places. Missing one fails silently (type renders as a bare text input, or values vanish from emails). Work through all of them.

Current types: see `Form::FIELD_TYPES` in `src/models/Form.php`.

## 1. Allowlist — `src/models/Form.php`

Add the type string to `Form::FIELD_TYPES`. Validation rejects unknown types, so nothing works until this is done.

## 2. Front-end render — `src/templates/_render/form.twig`

Add a branch to the type conditional (`field.type == '...'`). Follow the existing pattern:

- Bootstrap 5 classes (`form-control` / `form-select` / `form-check-input`), `is-invalid` when `fieldErrors`
- `name="fields[{{ field.handle }}]"`, `id="{{ inputId }}"`
- Repopulate from `value` after a failed submit
- `required` attribute when `field.required`
- Types with choices read comma-separated `field.options` (see the `select` branch)

Remember: projects can override this template entirely (`templates/recranet-forms/form.twig`), so keep the default markup accessible — labels tied via `for`, error text visible.

## 3. Validation — `src/controllers/SubmissionsController.php`

`validateContent()` has per-type rules (see the `email` and `select` branches). Add server-side validation for the new type: never trust the posted value — e.g. a choice type must reject values outside `field.options`. Error messages go through `Craft::t('recranet-forms', …)` → step 5.

## 4. Builder card — `src/templates/forms/_edit.twig`

The type appears automatically in the "Add a field" menu (it loops `fieldTypes`). Check whether the card needs more than the defaults:

- Types with choices must show the **options** input (see how `select` toggles it)
- New per-field settings mean extending the field JSON shape `{label, handle, type, required, options, width}` — update the card, the JS template (`#rf-field-template`), and `Form::validateFieldsShape`

## 5. Value formatting — emails + CP

- `src/templates/_emails/notification.twig` — how the value renders in the owner mail (see the `checkbox` → Yes/No branch)
- The boilerplate's override `templates/recranet-forms/_emails/notification.twig` needs the same branch
- `src/elements/Submission.php` / `src/templates/submissions/view.twig` — check the CP detail view renders the value sensibly
- New user-facing strings: add to **all six** `src/translations/*/recranet-forms.php`

## Verify

```bash
find src -name '*.php' -print0 | xargs -0 -n1 php -l
```

Then in a project with the plugin: create a form with the new field in the CP, submit once via the front end, and check (a) validation rejects bad input, (b) the value lands in `formData`, (c) the notification email shows it.
