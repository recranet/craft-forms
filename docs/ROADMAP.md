# Roadmap

Distilled from a comparison against Formie 3.1.34 (source), Solspace Freeform v5 (source) and Gravity Forms (docs), August 2026. Full reports live in the boilerplate session notes; this file keeps the decisions.

> **Licence guard:** Formie and Freeform are proprietary (source-available ≠ open source); Gravity Forms is closed. We take *ideas and publicly documented behaviours only* — never source code, templates, CSS naming (`fui-*`, `freeform-*`) or docs prose. Clean-room implementations under our own naming.

## Phase 0 — structural fixes (before real production data exists)

**✅ Shipped in v2.0.0 (2026-08-06).** Items 1–6 below are done: field uids + snapshot, element validation, sent/spam/failed statuses, metadata columns (incrementalId/token/sourceUrl/idempotencyKey/spamScore/sendError + native siteId), uninstall cleanup, a11y pass. Kept for the rationale:

1. **Field identity by UID, not handle.** All three competitors key stored content by a stable id; we key `formData` by handle, so renaming a field in the CP silently orphans every historical submission. Give each field row a `uid`, key `formData` by uid, and store a `snapshot` of the form definition on the submission. *(Formie: uid-keyed content column; Freeform: field id is authoritative, handle is a label.)*
2. **Validation on the element, not the controller.** Move `validateContent()` rules into `Submission::defineRules()` per-field-type rules. Gets repopulation, future AJAX JSON errors and CP-side validation for free.
3. **Separate `isSpam` from the status axis.** Keep spam as the boolean column it already is and free up element statuses for New/Read/Handled (hardcoded — no user-editable status table).
4. **Submission metadata columns:** `siteId` (we serve 6-locale sites and can't filter by site), `incrementalId` (human reference number, clients always ask), `sourceUrl`, unique `token` (enables tokenized GDPR view/delete links later), `idempotencyKey` (double-submit dedupe).
5. **Uninstall cleanup:** `beforeUninstall()` must remove our `elementSources.<Submission FQCN>` entries from project config — Craft writes them the moment an admin reorders index columns, and they drift on `allowAdminChanges: false` environments.
6. **A11y pass on the default template** (few hours, beats the market leader): `aria-describedby` → error/description ids, `aria-invalid` on errored inputs, `role="alert"` on the error summary, `autocomplete` tokens (`name`, `email`, `tel`) on matching field types.

## Phase 1 — interactive fields (the current goal)

The shared foundation is **one rule shape** used everywhere: `{field: <uid>, operator: is|isNot|contains|greaterThan, value}` groups combined with all/any. One UI component, one evaluator (PHP + JS), reused by fields, notifications *and* confirmations. Build the shape first, then the consumers:

1. **Conditional fields** — show/hide a field based on other fields' values. JS evaluator on the front end; server re-evaluates on submit. Copy Formie's hard-won rule: *a hidden field is never required*, whatever its required flag says (the bug everyone else shipped).
2. **Three-tab field settings** (General / Appearance / Advanced) in the builder card, replacing the flat card. General: label, handle, required, options. Appearance: placeholder, description, width, CSS class. Advanced: default value, admin label, custom validation message, conditional rules.
3. **Per-field description, placeholder, custom validation message, admin label** — cheap, disproportionately loved by editors.
4. **Duplicate-field button** on the card; **duplicate form** action later.
5. **Merge tags** — don't invent syntax: run notification/confirmation subject, body and reply-to through Craft's `renderObjectTemplate()` on the submission, so tags are `{naam}`, `{email}`. Small "insert field" dropdown in the CP. One helper for `{allFields}` (rendered table).

## Phase 2 — editor workflow

- **Confirmations per form:** text / redirect (merge tags in query string), with a non-deletable default as guaranteed fallback.
- **Multiple notifications per form**, each toggleable, Send To = fixed address / a form field / routing rules (`if onderwerp is X → x@…`) — reuses the Phase 1 rule shape.
- **Resend notification** element action (+ queue job).
- **CSV export** via `craft\base\ElementExporter` — mostly free from the element index, don't build custom.
- **Embed snippet + preview** in the form edit screen.
- **Notes on a submission** (optionally emailed) — later, only if editors ask.

## Phase 0.5 — secure-forms absorption

**✅ Shipped in v2.1.0 (2026-08-06).** Ported from `recranet/craft-secure-forms` (own IP): the captcha provider layer (reCAPTCHA v2/v3/Enterprise + Turnstile behind `CaptchaInterface`), token binding to form action + hostname, minimum fill time, sender blocklist, reject-vs-review spam split, storage switches (`saveSubmissions`, `saveSpamSubmissions`), retention (`retentionDays` + GC hook + `gc/prune`), the Email/SMTP test utility, and the expanded-fields CSV exporter. **Still open:** a `MigrateController` porting secure-forms submissions into recranet-forms, and the decision to archive secure-forms once that path exists.

## Phase 3 — spam & GDPR

- **Min-submit-time** check (store render timestamp, reject < N seconds): free, no third party, catches most bots.
- **One-time submit token** (replay/double-submit guard).
- **Throttle** per form+IP (cache counter, ~20 lines — none of the three do this well).
- **Blocked keywords/emails** simple list — skip expression languages.
- **Spam behaviour setting:** simulate-success (current, default) vs show-errors (debug); spam retention cap pruned by GC.
- **Data retention per form** (delete/anonymize after N days) via Craft GC hook + console command — the AVG question comes up on every client.
- **Consent field** that snapshots the consent text with the submission.

## Explicitly skipped (bloat for our use case)

Multi-page forms, save & continue/partial entries, payments/products, CRM & email-marketing integrations, quizzes/surveys/polls, GraphQL, stencils/import-export profiles, input masks (a11y-hostile — use `type`/`inputmode`/`pattern`), composite name/address fields, per-form content tables (Freeform's runtime-DDL anti-pattern), user-editable status tables, notification templates as CP-managed elements (overridable Twig in git is better).
