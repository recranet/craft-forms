# Release Notes for Recranet Forms

## 2.7.0 - 2026-08-06

- **Form text is translatable per site, from the control panel.** Labels, placeholders, descriptions and consent text, option labels, custom validation messages, the form name and the email subjects/bodies now have per-site translations stored in the database — editors change wording on production without a developer or a deploy. Pick a site from the breadcrumb site menu, the same switcher entries use. Structure (field types, handles, widths, rules) stays shared with the source form, and an empty translation falls back to the source text.
- **Translate with AI**, powered by `recranet/craft-ai-translator` when it's installed: fills the missing strings through that plugin's own provider, so the project glossary and tone of voice apply and merge tags are left alone. Existing translations are never overwritten; without the plugin the button is absent and nothing else changes.
- The front end, both emails and the previews all resolve the form for the site they're rendering in.

> Replaces the old approach of routing builder text through `translations/{locale}/site.php`. Those files still work as a fallback for anything without a database translation, so existing projects keep rendering as before.

## 2.6.0 - 2026-08-06

- **Emails now render in the right language.** They are rendered before `send()`, so Craft's own language swap never applied to them: a resend or "Not spam" from the control panel mailed the visitor in the *admin's* language. Both emails now render with the submission's site as the current site (language, site-specific globals and singles included), and the message carries its `siteId` so per-site from/reply-to overrides apply.
- New setting **Notification language**: follow the site the visitor submitted on (default), or always use the primary site's language for owners who don't read every locale their site serves. The visitor's confirmation is always in the visitor's own language.
- Fixed site-scoped submission lookups: the CP detail view, the Not spam / Resend actions and the tokenized self-service page could not find a submission made on another site (404), and **retention only ever pruned the current site's submissions** — a GDPR-relevant gap on multi-site installs.
- The plugin is now called **Forms** in the control panel (package and handle unchanged).

## 2.5.1 - 2026-08-06

- Fixed the Fields tab staying visible after switching tabs on screens from 1200px: the split-view `display: grid` beat the UA stylesheet's `[hidden] { display: none }`.
- Fixed the preview cache-buster producing `?site=nl?t=…` on multi-site installs — it now picks the right separator.

## 2.5.0 - 2026-08-06

- **False-positive recovery**: "Not spam" and "Resend notification" element actions on the submissions index (bulk, only on applicable rows) and as buttons on the detail view. Marking as not spam keeps the original reason as an audit trail and sends the emails that were skipped.
- **Throttle**: submissions per IP + form are rate-limited (default 5 per 60s); a hammering bot is rejected before any captcha call, while still seeing a success page.
- **Per-form retention** overrides the plugin-wide setting, with an **anonymize** mode that keeps the row for statistics and blanks all personal data (uploads deleted, self-service token destroyed, `anonymizedAt` stamped).
- **Preview panels**: the Fields tab shows a live split-view preview next to the builder on wide screens; the Notification and Confirmation tabs preview their emails with sample values, subject included and merge tags resolved. Same template resolution as a real render or send — nothing is sent or stored.
- **Form field type**: authors can drop a form into page content (entry body, Matrix block, CKEditor entry). Stores the form's uid, so a handle rename never breaks the reference.
- **Settings page split into five tabs** (Captcha / Spam checks / Storage / Retention / Uploads) with an intro per tab and sub-sections with hints.
- Accessibility: focus moves to the error summary after a failed submit, and radio/checkbox groups announce "(required)" — the asterisk is aria-hidden and checkbox groups carry no native required attribute.
- Saving a form keeps you on the form instead of returning to the overview.
- Added a CHANGELOG and a `test-forms` workflow (lint, four front-end e2e flows, CP smoke test).

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
