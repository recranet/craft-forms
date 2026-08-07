---
name: add-form-setting
description: Add a per-form setting to Recranet Forms — every place the settings JSON round-trips
---

# Add a per-form setting

Per-form settings live in the `settings` JSON column of `recranetforms_forms`. The JSON is (de)serialized in FOUR spots in `src/services/Forms.php` alone — miss one and the value silently resets on save, or vanishes from exports. Work through all of them.

## 1. Model — `src/models/Form.php`

Property with a docblock explaining what it does, + a `defineRules()` rule. Follow the retention pattern for "inherit vs. explicit" settings: `int|string` with `''` = inherit.

## 2. Service — `src/services/Forms.php` (four spots)

- `saveForm()` — add to the `settings` array that's encoded
- `createModel()` — read it back, with a default for forms saved before the setting existed (`$settings['x'] ?? <default>`)
- `exportForm()` — include it in the portable array
- `createFromExport()` — read it defensively (cast, validate enums, fall back on unknown values)

Console import/export (`src/console/controllers/FormsController.php`) goes through these same methods — no separate change.

## 3. Controller — `src/controllers/FormsController.php`

`actionSave()`: read the body param with the right cast + default. Mind the translation branch: non-primary-site saves post only translations and must NOT touch the setting.

## 4. Builder — `src/templates/forms/_edit.twig`

Input in the matching pane (mail settings, retention, …). CP labels via `|t('recranet-forms')`.

## 5. Translatable? — only for visitor/owner-facing text

If the value is text a visitor or mail recipient sees (like `confirmationBody`): add the key to `FormTranslations::FORM_KEYS`, decide plain-text vs HTML in `AiTranslate::HTML_KEY_SUFFIXES`, and add the translation input to the builder's translation mode (`isTranslation`). Structural settings are deliberately NOT translatable.

## 6. Translations

New CP strings in all six `src/translations/*/recranet-forms.php`.

## Verify

`php -l` sweep, then: save a form with the setting → reload the edit screen (value persisted?) → export → import (value survived?) → duplicate (value copied?). For inherit-style settings also check that an old form without the key behaves as "inherit".
