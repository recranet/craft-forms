<?php

namespace recranet\forms\payments;

use Craft;
use GuzzleHttp\Exception\GuzzleException;
use recranet\forms\elements\Submission;
use recranet\forms\models\Settings;

/**
 * Mollie hosted checkout via the Payments API (v2) — plain REST through
 * Craft's Guzzle, deliberately no mollie/mollie-api-php dependency: the two
 * endpoints we use (create payment, fetch payment) have been stable for
 * years, and no SDK means no SDK churn.
 *
 * Test mode is key-based: a `test_` key makes every payment a test payment
 * (Mollie's own model). isTestMode() feeds the CP/front-end badge.
 */
class Mollie implements PaymentProviderInterface
{
	private const API_BASE = 'https://api.mollie.com/v2';

	public function __construct(private Settings $settings)
	{
	}

	public function getName(): string
	{
		return 'Mollie';
	}

	public function isTestMode(): bool
	{
		return str_starts_with($this->settings->getMollieApiKey(), 'test_');
	}

	public function createPayment(int $amountCents, string $description, string $redirectUrl, ?string $webhookUrl): PaymentResult
	{
		$body = [
			'amount' => [
				'currency' => 'EUR',
				'value' => PaymentCalculator::formatForApi($amountCents),
			],
			'description' => $description,
			'redirectUrl' => $redirectUrl,
		];

		// Mollie rejects unreachable webhook URLs (localhost); on local dev
		// we omit it and the return-page poll picks the status up instead
		if ($webhookUrl !== null) {
			$body['webhookUrl'] = $webhookUrl;
		}

		$payment = $this->request('POST', '/payments', $body);

		$checkoutUrl = $payment['_links']['checkout']['href'] ?? null;

		if (empty($payment['id']) || !$checkoutUrl) {
			throw new PaymentError('Mollie created a payment without a checkout URL — response shape changed?');
		}

		return new PaymentResult((string)$payment['id'], (string)$checkoutUrl);
	}

	public function getPaymentStatus(string $paymentId): string
	{
		$payment = $this->request('GET', '/payments/' . rawurlencode($paymentId));

		// Mollie statuses → our normalized set. "open" (visitor still at the
		// checkout) and "pending"/"authorized" (processing) all stay pending;
		// paid/failed/expired/canceled map one-to-one.
		return match ($payment['status'] ?? '') {
			'paid' => Submission::PAYMENT_PAID,
			'failed' => Submission::PAYMENT_FAILED,
			'expired' => Submission::PAYMENT_EXPIRED,
			'canceled' => Submission::PAYMENT_CANCELED,
			default => Submission::PAYMENT_PENDING,
		};
	}

	public function getCheckoutUrl(string $paymentId): ?string
	{
		$payment = $this->request('GET', '/payments/' . rawurlencode($paymentId));

		// The checkout link only exists while the payment is still payable
		$url = $payment['_links']['checkout']['href'] ?? null;

		return $url ? (string)$url : null;
	}

	/**
	 * Authenticated JSON request to Mollie.
	 *
	 * @throws PaymentError on missing key, transport failure or a non-2xx
	 * response — all config/availability problems, never visitor spam
	 */
	private function request(string $method, string $path, ?array $body = null): array
	{
		$apiKey = $this->settings->getMollieApiKey();

		if ($apiKey === '') {
			throw new PaymentError('Mollie API key is not configured.');
		}

		try {
			$response = Craft::createGuzzleClient(['timeout' => 10])->request($method, self::API_BASE . $path, array_filter([
				'headers' => ['Authorization' => 'Bearer ' . $apiKey],
				'json' => $body,
			]));
		} catch (GuzzleException $e) {
			// Guzzle messages include the response body; Mollie's error
			// bodies are safe (no key echo), but truncate for the log
			throw new PaymentError('Mollie request failed: ' . mb_substr($e->getMessage(), 0, 300), 0, $e);
		}

		$data = json_decode((string)$response->getBody(), true);

		if (!is_array($data)) {
			throw new PaymentError('Mollie returned a malformed response.');
		}

		return $data;
	}
}
