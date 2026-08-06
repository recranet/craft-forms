<?php

namespace recranet\forms\controllers;

use Craft;
use craft\web\Controller;
use recranet\forms\captchas\CaptchaError;
use recranet\forms\elements\Submission;
use recranet\forms\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Handles the front-end submit action and the CP submission screens.
 *
 * Submit flow:
 * 1. Normalize + validate posted values on the Submission element (rules
 *    derive from the form snapshot stored on the element).
 * 2. Dedupe: an identical submit within the idempotency window is dropped
 *    silently (double click / double post).
 * 3. Honeypot filled → save flagged as spam, pretend success (don't tip off bots).
 * 4. reCAPTCHA verdict:
 *    - pass → continue
 *    - spam → save flagged, pretend success
 *    - error (broken config/infra) → NEVER treated as spam: fail-open accepts
 *      and flags the submission + logs a warning; fail-closed shows the
 *      visitor a real error.
 * 5. Save submission, send notification + optional confirmation. A send
 *    failure is recorded on the submission (status "failed"), never lost.
 */
class SubmissionsController extends Controller
{
	/** Window in which an identical re-submit counts as a duplicate */
	private const IDEMPOTENCY_WINDOW_SECONDS = 300;

	protected array|bool|int $allowAnonymous = ['submit'];

	public function actionSubmit(): ?Response
	{
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		$settings = Plugin::getInstance()->getSettings();

		$handle = $request->getValidatedBodyParam('formHandle');
		$form = $handle ? Plugin::getInstance()->forms->getFormByHandle($handle) : null;

		if (!$form) {
			throw new BadRequestHttpException('Unknown form.');
		}

		$submission = new Submission();
		$submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
		$submission->sourceUrl = '/' . ltrim($request->getPathInfo(), '/');
		$content = $submission->applyPost($form, (array)$request->getBodyParam('fields', []));

		if (!$submission->validate()) {
			// Re-render the page with errors and the submitted values
			Craft::$app->getUrlManager()->setRouteParams([
				'formErrors' => $submission->getFieldErrors(),
				'formContent' => $content,
				'formHandle' => $form->handle,
			]);

			return null;
		}

		// Double submit (same form, same content, moments apart): pretend
		// success without saving a second copy or mailing twice
		if ($this->isDuplicate($submission)) {
			Craft::$app->getSession()->setSuccess(Craft::t('recranet-forms', 'Thank you! Your message has been sent.'));

			return $this->redirectToPostedUrl($submission);
		}

		// Spam pipeline: blocklist → honeypot → timing → captcha + token binding.
		// A CaptchaError is a config/infra problem — our bug, never visitor spam.
		try {
			$verdict = Plugin::getInstance()->spam->check($request, $form, $submission);

			$submission->isSpam = $verdict->isSpam;
			$submission->spamScore = $verdict->score;
			$submission->spamReason = $verdict->reason;

			// Definite spam (honeypot, forged timestamp, score below the reject
			// threshold): don't store it, but pretend success so the bot learns
			// nothing about which check it tripped over
			if ($verdict->reject) {
				Craft::$app->getSession()->setSuccess(Craft::t('recranet-forms', 'Thank you! Your message has been sent.'));

				return $this->redirectToPostedUrl($submission);
			}
		} catch (CaptchaError $e) {
			Craft::warning("Form \"{$form->handle}\": {$e->getMessage()}", __METHOD__);

			if (!$settings->recaptchaFailOpen) {
				Craft::$app->getSession()->setError(Craft::t('recranet-forms', 'Something went wrong verifying your submission. Please try again later.'));
				Craft::$app->getUrlManager()->setRouteParams([
					'formContent' => $content,
					'formHandle' => $form->handle,
				]);

				return null;
			}

			// Fail open: accept but record why, so it's diagnosable in the CP
			$submission->spamReason = 'Accepted despite captcha error: ' . $e->getMessage();
		}

		// Storage switches: submissions can run mail-only, and spam storage
		// can be turned off separately (then flagged spam is simply dropped)
		$shouldSave = $settings->saveSubmissions && (!$submission->isSpam || $settings->saveSpamSubmissions);

		if ($shouldSave && !Craft::$app->getElements()->saveElement($submission)) {
			Craft::error("Form \"{$form->handle}\": failed to save submission: " . implode('; ', $submission->getFirstErrors()), __METHOD__);
			Craft::$app->getSession()->setError(Craft::t('recranet-forms', 'Something went wrong. Please try again later.'));

			return null;
		}

		// Spam submissions are stored but never emailed; visitor still sees
		// success. A notification send failure is recorded on the submission
		// (status "failed") — the data is already saved, nothing is lost.
		if (!$submission->isSpam) {
			if (!Plugin::getInstance()->notifications->sendNotification($form, $submission) && $shouldSave) {
				$submission->sendError = 'Notification email failed to send — see the log for the transport error.';
				Craft::$app->getElements()->saveElement($submission);
			}

			Plugin::getInstance()->notifications->sendConfirmation($form, $submission);
		}

		Craft::$app->getSession()->setSuccess(Craft::t('recranet-forms', 'Thank you! Your message has been sent.'));

		return $this->redirectToPostedUrl($submission);
	}

	/**
	 * An identical submission (same idempotency key) saved within the window
	 * means a double post — browser refresh, double click, retry.
	 */
	private function isDuplicate(Submission $submission): bool
	{
		// prepareDateForDb converts to the UTC storage format Craft uses
		$since = \craft\helpers\Db::prepareDateForDb(
			new \DateTimeImmutable('-' . self::IDEMPOTENCY_WINDOW_SECONDS . ' seconds'),
		);

		return (new \craft\db\Query())
			->from(['s' => '{{%recranetforms_submissions}}'])
			->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[s.id]]')
			->where(['s.idempotencyKey' => $submission->idempotencyKey])
			->andWhere(['>=', 'e.dateCreated', $since])
			->exists();
	}

	/**
	 * CP: submissions element index.
	 */
	public function actionIndex(): Response
	{
		$this->requireCpRequest();
		$this->requirePermission('recranetForms-viewSubmissions');

		return $this->renderTemplate('recranet-forms/submissions/index');
	}

	/**
	 * CP: single submission detail view.
	 */
	public function actionView(int $submissionId): Response
	{
		$this->requireCpRequest();
		$this->requirePermission('recranetForms-viewSubmissions');

		$submission = Submission::find()->id($submissionId)->status(null)->one();

		if (!$submission) {
			throw new NotFoundHttpException('Submission not found.');
		}

		return $this->renderTemplate('recranet-forms/submissions/view', [
			'submission' => $submission,
			'form' => $submission->getForm(),
		]);
	}
}
