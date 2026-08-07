---
name: payments
description: How Recranet Forms payments work — flow, money math, statuses, test mode, and adding a provider
---

# Payments

Hosted checkout only: the visitor pays on the provider's page, no payment data touches the site (no PCI scope, no embedded SDK churn). Mollie is the first provider (`src/payments/Mollie.php`, plain REST — deliberately no mollie SDK dependency).

## The flow

1. `SubmissionsController::actionSubmit` — after validation + spam pipeline, `Payments::amountFor()` computes the total. Spam never pays; amount ≤ 0 (free choices, no provider, payment off) = normal flow.
2. Amount due → submission saved with `paymentStatus: pending` + `paymentAmount` (whole **cents**, never floats), then `Payments::startPayment()` creates the provider payment and the visitor is redirected to its checkout. Payment forms ALWAYS store, mail-only mode does not apply (the row is the webhook lookup).
3. **Emails wait.** They go out exactly once, on the not-paid → paid transition in `Payments::syncStatus()` — called by BOTH the provider webhook (`recranet-forms/payment/webhook`, CSRF off, always answers 200) and the visitor's return page (`recranet-forms/payment/return/<token>`, which polls so local dev works without a reachable webhook — local hostnames skip the webhook URL entirely, see `isLocalHostname()`).
4. Element status: any non-paid paymentStatus → **"Awaiting payment"** (spam still wins). Parity lives in TWO places: `Submission::getStatus()` and `SubmissionQuery::statusCondition()`.

## Money math — `src/payments/PaymentCalculator.php`

Pure/static, unit-tested (`tests/payments/`). Everything comes from the FORM DEFINITION, never from posted amounts:

- Choice fields: `prices` list parallel to `options` ("25, 40.50, 0" — decimal POINT; the commas separate entries). Checkboxes sum every chosen option.
- Number fields: `price` per unit × value (negative quantities clamp to 0).
- `Form::paymentBase` flat on top. Everything in cents (`parseAmountCents`; single amounts also accept a decimal comma).
- Hidden-by-conditions fields contribute nothing (their values are already nulled by the discard cascade). A hostile option value prices nothing AND fails validation.

## Test mode

Key-based, like the providers themselves: a `test_` Mollie key = test mode. `isTestMode()` drives a badge in the form builder AND a visitor-facing note in `_render/form.twig` — a test setup must never look live. No separate toggle.

## Error philosophy (same as captchas)

`PaymentError` = config/availability problem, never the visitor's fault. Start-payment failure keeps the submission stored as "awaiting payment" + visitor sees an honest retry message. The webhook re-fetches status from the provider's API, so forged webhook calls can't change anything.

## Sharp edges

- Duplicate submits (idempotency window) with a pending payment redirect back to the SAME checkout (`checkoutUrlFor()`), never fake success.
- `syncStatus()` must stay idempotent: webhook and return-poll race; only the caller that performs the paid transition sends the emails.
- Prices are NOT translatable (`FormTranslations` — money is structure). Price inputs render only when the form has payment enabled; hidden inputs preserve the values otherwise.
- Statement description = form name + `#<ref>`.

## Adding a provider (e.g. Stripe)

1. `src/payments/Stripe.php` implementing `PaymentProviderInterface` (createPayment → hosted session URL, getPaymentStatus → map onto `Submission::PAYMENT_*`, getCheckoutUrl, isTestMode via key prefix). Throw `PaymentError` for config problems.
2. `Settings`: `PAYMENT_STRIPE` const + key property + `App::parseEnv` getter + 'in' rule.
3. `Payments::getProvider()` match arm.
4. `settings.twig` payments pane: provider option + key fields in a `data-rfs-payment-show="stripe"` block.
5. Translations ×6 for new CP strings.
6. Verify with the provider's test key: submit → checkout → pay with a test method → return page flashes success → emails arrived → CP shows paid.
