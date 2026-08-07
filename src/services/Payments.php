<?php

namespace recranet\forms\services;

use Craft;
use craft\helpers\UrlHelper;
use recranet\forms\elements\Submission;
use recranet\forms\models\Form;
use recranet\forms\models\Settings;
use recranet\forms\payments\Mollie;
use recranet\forms\payments\PaymentCalculator;
use recranet\forms\payments\PaymentError;
use recranet\forms\payments\PaymentProviderInterface;
use recranet\forms\payments\PaymentResult;
use recranet\forms\Plugin;
use yii\base\Component;

/**
 * Orchestrates hosted-checkout payments around a submission: computes the
 * amount from the form definition, starts the provider payment, and syncs
 * the status back (webhook or return-page poll). Emails go out ONLY on the
 * transition to paid — that's the whole deal of a payment form.
 */
class Payments extends Component
{
	/**
	 * The active provider, or null when payments are disabled plugin-wide.
	 */
	public function getProvider(): ?PaymentProviderInterface
	{
		$settings = Plugin::getInstance()->getSettings();

		return match ($settings->paymentProvider) {
			Settings::PAYMENT_MOLLIE => new Mollie($settings),
			default => null,
		};
	}

	/**
	 * What this submission owes, in whole cents. 0 = nothing to pay (also
	 * when the form has no payment or no provider is configured). Uses the
	 * post-discard formData, so options on condition-hidden fields never
	 * count toward the total.
	 */
	public function amountFor(Form $form, Submission $submission): int
	{
		if (!$form->paymentEnabled || $this->getProvider() === null) {
			return 0;
		}

		return max(0, PaymentCalculator::totalCents($form, $form->fields, $submission->formData));
	}

	/**
	 * Create the provider payment for an already-saved submission and return
	 * where to send the visitor. Persists paymentId on the submission.
	 *
	 * @throws PaymentError configuration/availability problems — the caller
	 * decides what the visitor sees; the submission is already safe in the DB
	 */
	public function startPayment(Form $form, Submission $submission): PaymentResult
	{
		$provider = $this->getProvider();

		if ($provider === null) {
			throw new PaymentError('No payment provider is configured.');
		}

		// The return URL carries the submission token: unguessable, and the
		// return action re-polls the provider so the visitor never has to
		// wait for the webhook
		$redirectUrl = UrlHelper::siteUrl('recranet-forms/payment/return/' . $submission->token);

		// Providers reject webhook URLs they can't reach; on local hostnames
		// we skip the webhook entirely and lean on the return poll
		$webhookUrl = $this->isLocalHostname()
			? null
			: UrlHelper::siteUrl('recranet-forms/payment/webhook');

		$result = $provider->createPayment(
			$submission->paymentAmount,
			// Statement/receipt line: form name + human reference
			$form->name . ' #' . $submission->incrementalId,
			$redirectUrl,
			$webhookUrl,
		);

		$submission->paymentId = $result->id;
		Craft::$app->getElements()->saveElement($submission, runValidation: false);

		return $result;
	}

	/**
	 * Re-fetch the payment status from the provider and apply it. On the
	 * transition to PAID the deferred notification + confirmation emails go
	 * out — exactly once: a webhook and a return-poll racing each other both
	 * call this, and only the one that performs the transition sends.
	 *
	 * @throws PaymentError when the provider can't be reached
	 */
	public function syncStatus(Submission $submission): string
	{
		$provider = $this->getProvider();

		if ($provider === null || !$submission->paymentId) {
			return $submission->paymentStatus ?? Submission::PAYMENT_PENDING;
		}

		$status = $provider->getPaymentStatus($submission->paymentId);

		if ($status === $submission->paymentStatus) {
			return $status;
		}

		$wasPaid = $submission->paymentStatus === Submission::PAYMENT_PAID;
		$submission->paymentStatus = $status;

		// Deferred emails, exactly on the not-paid → paid transition
		if (!$wasPaid && $status === Submission::PAYMENT_PAID) {
			$form = $submission->getForm();

			if ($form) {
				if (!Plugin::getInstance()->notifications->sendNotification($form, $submission)) {
					$submission->sendError = Submission::SEND_ERROR_NOTIFICATION;
				}

				Plugin::getInstance()->notifications->sendConfirmation($form, $submission);
			} else {
				Plugin::error("Submission #{$submission->id}: paid, but form #{$submission->formId} no longer exists — no emails sent.");
			}
		}

		Craft::$app->getElements()->saveElement($submission, runValidation: false);

		return $status;
	}

	/**
	 * The still-open checkout URL for a submission's payment, or null when
	 * it is no longer payable (or anything goes wrong — the caller falls
	 * back to the normal duplicate handling, this is best-effort).
	 */
	public function checkoutUrlFor(Submission $submission): ?string
	{
		$provider = $this->getProvider();

		if ($provider === null || !$submission->paymentId) {
			return null;
		}

		try {
			return $provider->getCheckoutUrl($submission->paymentId);
		} catch (PaymentError $e) {
			Plugin::error("Could not re-fetch the checkout URL for submission #{$submission->id}: {$e->getMessage()}");

			return null;
		}
	}

	/**
	 * Hostnames a payment provider can never call back: local development.
	 */
	private function isLocalHostname(): bool
	{
		$hostname = strtolower((string)Craft::$app->getRequest()->getHostName());

		foreach (['.ddev.site', '.test', '.local', '.localhost'] as $suffix) {
			if (str_ends_with($hostname, $suffix)) {
				return true;
			}
		}

		return in_array($hostname, ['localhost', '127.0.0.1', '::1'], true);
	}
}
