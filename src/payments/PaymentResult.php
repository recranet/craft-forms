<?php

namespace recranet\forms\payments;

/**
 * A freshly created hosted-checkout payment: the provider's id (stored on
 * the submission for the webhook/return lookup) and the URL to send the
 * visitor to.
 */
final class PaymentResult
{
	public function __construct(
		public readonly string $id,
		public readonly string $checkoutUrl,
	) {
	}
}
