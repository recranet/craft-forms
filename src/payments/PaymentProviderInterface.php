<?php

namespace recranet\forms\payments;

/**
 * A payment provider that can start a hosted-checkout payment and report its
 * status. Deliberately tiny — redirect checkout only, no embedded elements,
 * no tokens/cards touching this plugin (that keeps us out of PCI scope and
 * out of SDK maintenance).
 *
 * Provider errors follow the captcha pattern: configuration/availability
 * problems throw PaymentError; they are never a visitor's fault and never
 * silently swallowed.
 */
interface PaymentProviderInterface
{
	/** Human-readable name for logs and the CP */
	public function getName(): string;

	/**
	 * Whether the configured key is a test-mode key. Surfaced as a badge in
	 * the CP and on the rendered form, so nobody mistakes a test setup for
	 * a live one.
	 */
	public function isTestMode(): bool;

	/**
	 * Create a hosted-checkout payment.
	 *
	 * @param int $amountCents amount in whole cents (EUR)
	 * @param string $description shows up on the visitor's statement/receipt
	 * @param string $redirectUrl where the provider sends the visitor back
	 * @param string|null $webhookUrl status-change callback; null on
	 * environments the provider can't reach (local dev) — the return poll
	 * covers those
	 * @throws PaymentError on configuration or availability problems
	 */
	public function createPayment(int $amountCents, string $description, string $redirectUrl, ?string $webhookUrl): PaymentResult;

	/**
	 * Current status of a payment, normalized to the Submission::PAYMENT_*
	 * values (pending/paid/failed/expired/canceled).
	 *
	 * @throws PaymentError when the provider can't be reached
	 */
	public function getPaymentStatus(string $paymentId): string;

	/**
	 * The checkout URL of a still-open payment, or null when the payment is
	 * no longer payable. Lets a double-submitted form send the visitor back
	 * to the same checkout instead of faking success.
	 *
	 * @throws PaymentError when the provider can't be reached
	 */
	public function getCheckoutUrl(string $paymentId): ?string;
}
