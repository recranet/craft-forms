---
name: release
description: Release a new version of recranet/craft-forms to Packagist (tag-based)
---

# Release

Packagist follows git tags on https://github.com/recranet/craft-forms — no version field in composer.json (intentional; don't add one back).

## Steps

1. Working tree clean, on `main`, all changes committed, CI green.
2. `composer lint && composer test` — both must pass.
3. Move the CHANGELOG's "Unreleased" section under the new version heading (with today's date).
4. Pick the version (semver):
   - **patch** (v1.0.x) — bug fixes, template tweaks, translations
   - **minor** (v1.x.0) — new field types, new settings, new features (backwards compatible)
   - **major** (vx.0.0) — schema changes needing a migration, renamed handles/tables, breaking template variables
5. Any DB schema change needs a migration in `src/migrations/` **and** a `schemaVersion` bump in `src/Plugin.php` — Craft only runs plugin migrations when `schemaVersion` increases.
6. Tag and push:

```bash
git tag -a vX.Y.Z -m "Recranet Forms X.Y.Z"
git push origin main vX.Y.Z
```

7. Packagist auto-updates via the GitHub hook. Verify:

```bash
curl -sS https://repo.packagist.org/p2/recranet/craft-forms.json | python3 -c "import json,sys; [print(p['version']) for p in json.load(sys.stdin)['packages']['recranet/craft-forms']]"
```

8. In a consuming project: `ddev composer update recranet/craft-forms && ddev craft up`.

## Never

- Never retag an existing version (Packagist caches dists; consumers get checksum mismatches). Botched release → new patch tag.
- Never commit secrets — the repo is public. reCAPTCHA keys stay env-var references (`$RECAPTCHA_SITE_KEY`).
