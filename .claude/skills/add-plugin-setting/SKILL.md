---
name: add-plugin-setting
description: Add a plugin-wide setting to Recranet Forms — Settings model, settings screen, env-var support
---

# Add a plugin-wide setting

Plugin settings are Craft project config: on production with `allowAdminChanges` off they are read-only. Anything editors must manage on production belongs on the **form** instead (see `add-form-setting`) — that's why forms are DB content. Use a plugin setting only for infrastructure-level config (keys, thresholds, volumes, retention defaults).

## 1. Model — `src/models/Settings.php`

- Property with a docblock: what it does AND what 0/empty means (existing settings document their disable semantics — keep that up)
- Numeric settings are typed `int|string`/`float|string` with a typed getter (`(int)` cast) — project config may hand back strings
- Anything secret or per-environment: default to a `$ENV_VAR` reference and add an `App::parseEnv`-based getter (follow `getRecaptchaSiteKey()`). No literal secrets ever — public repo
- List-shaped values: comma-separated string + a getter that trims/lowercases/filters (follow `getAllowedFileExtensions()`)
- `defineRules()` entry

## 2. Settings screen — `src/templates/settings.twig`

Field in the matching tab pane (spam protection / emails / storage & retention / uploads / payments — `rfs-` prefix; all panes stay in the DOM, one form posts everything). Keys and env-var fields use Craft's `autosuggestField` with `suggestEnvVars: true`.

## 3. Consumers

Read via `Plugin::getInstance()->getSettings()-><getter>()` — always the getter, never the raw property (env parsing + casts live there).

## 4. Translations

Label + instructions in all six `src/translations/*/recranet-forms.php`.

## Verify

`php -l` sweep; save the settings screen and reload (validation rule fires? value persists?); set the value via `.env` reference and confirm the getter resolves it. If the setting gates a feature (like `uploadVolume`), check the disabled state degrades with a clear message, not a fatal.
