# Release Notes for Recranet Forms

## 2.4.1 - 2026-08-06

- Form edit screen reorganized into page-level tabs: Form settings / Fields / Notification / Confirmation. New forms (and name/handle validation errors) open on Settings; saved forms open on Fields.

## 2.4.0 - 2026-08-06

- Builder redesign: cards are collapsed by default with category-colored type icons and live status chips (required / conditional / half width); the field list is a two-column grid mirroring the site layout; free 2D dragging with insertion marker and landing flash.
- "Add a field" is now a searchable palette with an icon, name and description per type.
- Keyboard support in the builder: Enter/Space toggles a card, Alt+arrow keys move it (with screen-reader announcements).
- Fixed the CP sidebar icon: added `icon-mask.svg` (Craft uses the monochrome mask for the nav, not `icon.svg`).

## 2.3.0 - 2026-08-06

- Ten new field types: radio, checkboxes (multi-value), number, date, url, hidden (query-param prefill), consent (text snapshotted with the submission), heading and paragraph (layout-only), and single file upload to a private volume with extension/size validation and asset cleanup on hard delete.
- Granular permissions: Manage forms, View submissions and Delete submissions replace the blanket plugin-access gate.
- Per-form mail settings: template picker (dropdown of site templates), editable notification intro and confirmation body — both support merge tags and are editable on production.
- Form export/import as JSON (CP + `php craft recranet-forms/forms/export|import`) and a Duplicate action (fresh field uids, conditions remapped).
- AVG/GDPR self-service: `{selfServiceUrl}` merge tag links the submitter to a tokenized page to view or permanently delete their own submission.
- Multi-site: builder-entered labels, placeholders, descriptions, option labels and validation messages translate via Craft's `site` static translations.
- Fully translated control panel (nl/en/de/fr/es/it).
- Fixed a Twig precedence bug that appended `?submitted=true` to caller-provided redirects, and the confirmation email reading the pre-2.0 formData API.

## 2.2.0 - 2026-08-06

- Conditional fields: show/hide rules ({mode, rules[{field: uid, operator, value}]}) with strict PHP/JS evaluation parity, fixpoint cascade, and the guarantee that a hidden field is never required.
- Builder cards get General / Appearance / Advanced tabs with placeholder, description, admin label, default value, custom validation message and a visual rule editor.
- Duplicate-field button (new uid — never shared).
- Merge tags via Craft object templates in notification/confirmation subjects and recipients (`Aanvraag {onderwerp} — #{ref}`, `{afdeling}@example.com`).

## 2.1.0 - 2026-08-06

- Absorbed secure-forms: multi-provider captcha (reCAPTCHA v2/v3/Enterprise, Cloudflare Turnstile) behind a provider interface, token binding to form action + hostname, minimum fill time, sender blocklist, and a reject-vs-reviewable spam split.
- Storage switches (`saveSubmissions` mail-only mode, `saveSpamSubmissions`) and retention (`retentionDays`, Craft GC + `php craft recranet-forms/gc/prune`).
- Email / SMTP test utility (works with `allowAdminChanges` off).
- Expanded-fields CSV exporter on the submissions index.
- Health check moved to `php craft recranet-forms/captcha/check`.

## 2.0.0 - 2026-08-06

> **Breaking:** `formData` is keyed by field uid; templates reading `formData[handle]` must switch to `submission.values` / `submission.value('handle')`.

- Field rows carry a stable `uid`; submissions store a `snapshot` of the form definition at submit time — renaming a field never orphans data.
- Validation moved onto the Submission element.
- Statuses sent/spam/failed; mail send failures are recorded (`sendError`), never lost.
- Submission metadata: per-form reference number, unguessable token, source URL, idempotency key (double submits within 5 minutes dedupe), captcha score.
- Accessibility pass on the default template; project-config cleanup on uninstall.

## 1.0.1 - 2026-08-06

- Plugin icon, CLAUDE.md, skills and roadmap; `rf-` prefix replaces leftover `ef-` naming.

## 1.0.0 - 2026-08-06

- Initial public release as `recranet/craft-forms` (renamed from elloro/craft-forms): CP form builder, stored submissions as elements, honest reCAPTCHA v3 handling (config errors are never spam), honeypot, notification + confirmation emails, six front-end locales.
