<?php

namespace recranet\forms\controllers;

use Craft;
use craft\web\Controller;
use recranet\forms\elements\Submission;
use recranet\forms\payments\PaymentError;
use recranet\forms\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Front-end payment endpoints: the visitor's return from the hosted
 * checkout, and the provider's server-to-server webhook.
 */
class PaymentsController extends Controller
{
	protected array|bool|int $allowAnonymous = ['return', 'webhook'];

	// The webhook is a server-to-server POST from the provider — it carries
	// no CSRF token by definition. It is safe without one: the handler only
	// re-fetches the authoritative status from the provider's API, so a
	// forged call can't change anything the provider doesn't confirm.
	public $enableCsrfValidation = false;

	/**
	 * Visitor returns from the checkout. Poll the provider (the webhook may
	 * not have arrived yet — or can't arrive at all on local dev), flash the
	 * outcome and send the visitor back to the page they submitted from.
	 */
	public function actionReturn(string $token): Response
	{
		$submission = $this->submissionByToken($token);
		$session = Craft::$app->getSession();

		try {
			$status = Plugin::getInstance()->payments->syncStatus($submission);
		} catch (PaymentError $e) {
			Plugin::error("Payment status check failed for submission #{$submission->id}: {$e->getMessage()}");
			$session->setError(Craft::t('recranet-forms', 'We could not verify your payment yet. If you completed it, you will receive a confirmation shortly.'));

			return $this->redirect($submission->sourceUrl ?: '/');
		}

		if ($status === Submission::PAYMENT_PAID) {
			$session->setSuccess(Craft::t('recranet-forms', 'Thank you! Your payment has been received.'));
		} else {
			$session->setError(Craft::t('recranet-forms', 'Your payment was not completed. Please try again.'));
		}

		return $this->redirect($submission->sourceUrl ?: '/');
	}

	/**
	 * Provider webhook (Mollie POSTs `id=<paymentId>`). Always answers 200 —
	 * the status is re-fetched from the provider's API, so an unknown or
	 * forged id simply does nothing, and a non-200 would only make the
	 * provider retry a call we can't use.
	 */
	public function actionWebhook(): Response
	{
		$this->requirePostRequest();

		$paymentId = trim((string)Craft::$app->getRequest()->getBodyParam('id'));

		if ($paymentId !== '') {
			$submission = Submission::find()
				->siteId('*')
				->status(null)
				->andWhere(['recranetforms_submissions.paymentId' => $paymentId])
				->one();

			if ($submission) {
				try {
					Plugin::getInstance()->payments->syncStatus($submission);
				} catch (PaymentError $e) {
					Plugin::error("Payment webhook sync failed for submission #{$submission->id}: {$e->getMessage()}");
				}
			}
		}

		return $this->asRaw('OK');
	}

	private function submissionByToken(string $token): Submission
	{
		$submission = Submission::find()
			->siteId('*')
			->status(null)
			->andWhere(['recranetforms_submissions.token' => $token])
			->one();

		if (!$submission) {
			throw new NotFoundHttpException('Submission not found.');
		}

		return $submission;
	}
}
