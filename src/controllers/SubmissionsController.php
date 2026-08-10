<?php

namespace recranet\forms\controllers;

use Craft;
use craft\web\Controller;
use recranet\forms\captchas\CaptchaError;
use recranet\forms\elements\Submission;
use recranet\forms\models\Settings;
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

	protected array|bool|int $allowAnonymous = ['submit', 'view-by-token', 'delete-by-token'];

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

		$fieldErrors = $submission->validate() ? [] : $submission->getFieldErrors();

		// A configured privacy page makes agreement mandatory on every form:
		// the template renders a required checkbox (unless the form carries
		// its own consent field — then THAT is the agreement), and this is
		// the server half a hostile client can't skip.
		if ($settings->getPrivacyPolicyUrl() !== ''
			&& !$form->hasConsentField()
			&& !$request->getBodyParam('rfPrivacyConsent')
		) {
			$fieldErrors['rfPrivacyConsent'] = [Craft::t('recranet-forms', 'Please agree to the privacy statement.')];
		}

		if ($fieldErrors) {
			// Ajax: field errors as JSON, keyed by handle — same shape the
			// template renders server-side
			if ($request->getAcceptsJson()) {
				return $this->asJson(['success' => false, 'errors' => $fieldErrors]);
			}

			// Re-render the page with errors and the submitted values
			Craft::$app->getUrlManager()->setRouteParams([
				'formErrors' => $fieldErrors,
				'formContent' => $content,
				'formHandle' => $form->handle,
			]);

			return null;
		}

		// Double submit (same form, same content, moments apart): don't save
		// a second copy or mail twice. A duplicate with an unfinished payment
		// goes back to its checkout instead of faking success — a double
		// click must never swallow the payment step.
		if ($duplicate = $this->findDuplicate($submission)) {
			if ($duplicate->paymentStatus === Submission::PAYMENT_PENDING && $duplicate->paymentId) {
				$checkoutUrl = Plugin::getInstance()->payments->checkoutUrlFor($duplicate);

				if ($checkoutUrl) {
					return $request->getAcceptsJson()
						? $this->asJson(['success' => true, 'redirect' => $checkoutUrl])
						: $this->redirect($checkoutUrl);
				}
			}

			return $this->successResponse($form, $submission);
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
			// nothing about which check it tripped over — unless the spam
			// behaviour setting is in its debug mode
			if ($verdict->reject) {
				if ($settings->spamBehavior === Settings::SPAM_BEHAVIOR_SHOW_ERRORS) {
					return $this->spamErrorResponse($form, $content, $verdict->reason);
				}

				return $this->successResponse($form, $submission);
			}
		} catch (CaptchaError $e) {
			Craft::warning("Form \"{$form->handle}\": {$e->getMessage()}", __METHOD__);

			if (!$settings->recaptchaFailOpen) {
				$errorMessage = Craft::t('recranet-forms', 'Something went wrong verifying your submission. Please try again later.');

				if ($request->getAcceptsJson()) {
					return $this->asJson(['success' => false, 'message' => $errorMessage, 'errors' => []]);
				}

				Craft::$app->getSession()->setError($errorMessage);
				Craft::$app->getUrlManager()->setRouteParams([
					'formContent' => $content,
					'formHandle' => $form->handle,
				]);

				return null;
			}

			// Fail open: accept but record why, so it's diagnosable in the CP
			$submission->spamReason = 'Accepted despite captcha error: ' . $e->getMessage();
		}

		// Payment forms: the amount is computed server-side from the form
		// definition; anything > 0 defers the emails to the paid transition
		$amountCents = $submission->isSpam ? 0 : Plugin::getInstance()->payments->amountFor($form, $submission);

		// Storage switches: submissions can run mail-only, and spam storage
		// can be turned off separately (then flagged spam is simply dropped).
		// A payment NEEDS the stored row (status, webhook lookup), so payment
		// forms always store — mail-only mode cannot apply to them.
		$shouldSave = ($settings->saveSubmissions || $amountCents > 0) && (!$submission->isSpam || $settings->saveSpamSubmissions);

		if ($amountCents > 0) {
			$submission->paymentAmount = $amountCents;
			$submission->paymentStatus = Submission::PAYMENT_PENDING;
		}

		if ($shouldSave && !Craft::$app->getElements()->saveElement($submission)) {
			Craft::error("Form \"{$form->handle}\": failed to save submission: " . implode('; ', $submission->getFirstErrors()), __METHOD__);
			$errorMessage = Craft::t('recranet-forms', 'Something went wrong. Please try again later.');

			if ($request->getAcceptsJson()) {
				return $this->asJson(['success' => false, 'message' => $errorMessage, 'errors' => []]);
			}

			Craft::$app->getSession()->setError($errorMessage);
			// Repopulate the form so the visitor's input survives the error
			Craft::$app->getUrlManager()->setRouteParams([
				'formContent' => $content,
				'formHandle' => $form->handle,
			]);

			return null;
		}

		// Payment due: hand the visitor to the hosted checkout. Emails wait
		// for the paid webhook/return-poll; the submission is already safe.
		if ($amountCents > 0) {
			try {
				$result = Plugin::getInstance()->payments->startPayment($form, $submission);

				return $request->getAcceptsJson()
					? $this->asJson(['success' => true, 'redirect' => $result->checkoutUrl])
					: $this->redirect($result->checkoutUrl);
			} catch (\recranet\forms\payments\PaymentError $e) {
				// Config/availability problem — our fault, never the visitor's.
				// The submission stays stored as "awaiting payment" so nothing
				// is lost and the editor can see and chase it.
				Plugin::error("Form \"{$form->handle}\": could not start the payment: {$e->getMessage()}");
				$errorMessage = Craft::t('recranet-forms', 'Your submission was received, but the payment could not be started. Please try again later.');

				if ($request->getAcceptsJson()) {
					return $this->asJson(['success' => false, 'message' => $errorMessage, 'errors' => []]);
				}

				Craft::$app->getSession()->setError($errorMessage);

				return $this->redirectToPostedUrl($submission);
			}
		}

		// Spam submissions are stored but never emailed; visitor still sees
		// success. A notification send failure is recorded on the submission
		// (status "failed") — the data is already saved, nothing is lost.
		if (!$submission->isSpam) {
			if (!Plugin::getInstance()->notifications->sendNotification($form, $submission) && $shouldSave) {
				$submission->sendError = Submission::SEND_ERROR_NOTIFICATION;
				Craft::$app->getElements()->saveElement($submission);
			}

			Plugin::getInstance()->notifications->sendConfirmation($form, $submission);
		}

		// Reviewable spam is stored as usual, but in debug mode the visitor is
		// told instead of shown the simulated success
		if ($submission->isSpam && $settings->spamBehavior === Settings::SPAM_BEHAVIOR_SHOW_ERRORS) {
			return $this->spamErrorResponse($form, $content, $submission->spamReason);
		}

		return $this->successResponse($form, $submission);
	}

	/**
	 * The per-form success behavior: an editor-managed message (translated
	 * per site, default thank-you when empty), or a redirect to a page. The
	 * per-form redirect wins over a template-posted one — form behavior is
	 * content, editable on production; the template `redirect` option is the
	 * fallback for forms without one.
	 *
	 * Ajax callers get JSON: {success, message, redirect} — the inline form
	 * JS shows the message or follows the redirect.
	 */
	private function successResponse(\recranet\forms\models\Form $form, Submission $submission): Response
	{
		// Visitor-facing text follows the current site's translation
		$translated = Plugin::getInstance()->formTranslations->applyTo($form);
		$message = $translated->successMessage !== ''
			? Craft::t('site', $translated->successMessage)
			: Craft::t('recranet-forms', 'Thank you! Your message has been sent.');

		$redirect = null;

		if ($form->successBehavior === \recranet\forms\models\Form::SUCCESS_REDIRECT && $form->successRedirect !== '') {
			$redirect = str_starts_with($form->successRedirect, 'http')
				? $form->successRedirect
				: \craft\helpers\UrlHelper::siteUrl($form->successRedirect);
		}

		if (Craft::$app->getRequest()->getAcceptsJson()) {
			return $this->asJson(['success' => true, 'message' => $message, 'redirect' => $redirect]);
		}

		Craft::$app->getSession()->setSuccess($message);

		return $redirect ? $this->redirect($redirect) : $this->redirectToPostedUrl($submission);
	}

	/**
	 * The debug half of the spam behaviour setting: tell the visitor their
	 * submission was flagged instead of simulating success, with the reason
	 * on display — this mode exists to diagnose false positives together
	 * with the affected visitor. Production keeps 'simulate', so bots never
	 * see this response.
	 */
	private function spamErrorResponse(\recranet\forms\models\Form $form, array $content, ?string $reason): ?Response
	{
		$message = Craft::t('recranet-forms', 'Your submission was flagged as spam and has not been sent.');

		if ($reason) {
			$message .= ' (' . $reason . ')';
		}

		if (Craft::$app->getRequest()->getAcceptsJson()) {
			return $this->asJson(['success' => false, 'message' => $message, 'errors' => []]);
		}

		Craft::$app->getSession()->setError($message);
		// Repopulate the form so a wrongly flagged visitor keeps their input
		Craft::$app->getUrlManager()->setRouteParams([
			'formContent' => $content,
			'formHandle' => $form->handle,
		]);

		return null;
	}

	/**
	 * An identical submission (same idempotency key) saved within the window
	 * means a double post — browser refresh, double click, retry. Returns
	 * the earlier submission so the caller can see its payment state.
	 */
	private function findDuplicate(Submission $submission): ?Submission
	{
		// prepareDateForDb converts to the UTC storage format Craft uses
		$since = \craft\helpers\Db::prepareDateForDb(
			new \DateTimeImmutable('-' . self::IDEMPOTENCY_WINDOW_SECONDS . ' seconds'),
		);

		return Submission::find()
			->siteId('*')
			->status(null)
			->andWhere(['recranetforms_submissions.idempotencyKey' => $submission->idempotencyKey])
			->andWhere(['>=', 'elements.dateCreated', $since])
			->one();
	}

	/**
	 * Front end: a submitter views their own submission via the unguessable
	 * token from the confirmation email (AVG/GDPR self-service — no login).
	 * Site override: templates/recranet-forms/submission.twig.
	 */
	public function actionViewByToken(string $token): Response
	{
		$submission = $this->submissionByToken($token);

		$view = Craft::$app->getView();
		$variables = [
			'submission' => $submission,
			'form' => $submission->getForm(),
		];

		if ($view->doesTemplateExist('recranet-forms/submission', \craft\web\View::TEMPLATE_MODE_SITE)) {
			$html = $view->renderTemplate('recranet-forms/submission', $variables, \craft\web\View::TEMPLATE_MODE_SITE);
		} else {
			$html = $view->renderTemplate('recranet-forms/_render/submission', $variables, \craft\web\View::TEMPLATE_MODE_CP);
		}

		return $this->asRaw($html);
	}

	/**
	 * Front end: a submitter deletes their own submission (hard delete —
	 * this is the AVG erasure path, trash would defeat the purpose).
	 */
	public function actionDeleteByToken(): Response
	{
		$this->requirePostRequest();

		$token = (string)Craft::$app->getRequest()->getRequiredBodyParam('token');
		$submission = $this->submissionByToken($token);

		Craft::$app->getElements()->deleteElement($submission, hardDelete: true);
		Craft::$app->getSession()->setSuccess(Craft::t('recranet-forms', 'Your submission has been deleted.'));

		return $this->redirect(Craft::$app->getSites()->getCurrentSite()->getBaseUrl() ?? '/');
	}

	/**
	 * Resolve a submission by its self-service token or 404. Spam-flagged
	 * submissions resolve too — the sender may rightfully erase those.
	 */
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

	/**
	 * CP: submissions element index.
	 */
	public function actionIndex(): Response
	{
		$this->requireCpRequest();
		$this->requirePermission('recranetForms-viewSubmissions');

		// An editor opening an empty list can't tell "nothing arrived yet" from
		// "something is broken". Craft's element index only says "Nothing yet",
		// so the template adds context — but only while there is genuinely
		// nothing, and it needs to know whether any form even exists.
		$hasSubmissions = Submission::find()->siteId('*')->status(null)->exists();

		return $this->renderTemplate('recranet-forms/submissions/index', [
			'hasSubmissions' => $hasSubmissions,
			'formCount' => $hasSubmissions ? null : count(Plugin::getInstance()->forms->getAllForms()),
		]);
	}

	/**
	 * CP: single submission detail view.
	 */
	public function actionView(int $submissionId): Response
	{
		$this->requireCpRequest();
		$this->requirePermission('recranetForms-viewSubmissions');

		// siteId('*') — a submission belongs to the site it was made on, and
		// an editor works in whatever site the CP happens to be set to
		$submission = Submission::find()->id($submissionId)->siteId('*')->status(null)->one();

		if (!$submission) {
			throw new NotFoundHttpException('Submission not found.');
		}

		return $this->renderTemplate('recranet-forms/submissions/view', [
			'submission' => $submission,
			'form' => $submission->getForm(),
		]);
	}

	/**
	 * CP: false-positive recovery from the detail view. Clears the spam flag
	 * (keeping the reason as "Overridden: …") and sends the notification +
	 * confirmation emails that were skipped. Same effect as the "Not spam"
	 * element action on the index.
	 */
	public function actionMarkHam(): Response
	{
		$this->requireCpRequest();
		$this->requirePostRequest();
		$this->requirePermission('recranetForms-viewSubmissions');

		$submission = $this->requireCpSubmission();
		$session = Craft::$app->getSession();

		// Nothing to override (e.g. a stale tab double-posting) — just go back
		if (!$submission->isSpam) {
			return $this->redirect($submission->getCpEditUrl());
		}

		// Without the form there is no mail config to send with — bail with
		// a flash instead of half-processing
		if (!$submission->getForm()) {
			$session->setError(Craft::t('recranet-forms', 'The form this submission belongs to no longer exists.'));

			return $this->redirect($submission->getCpEditUrl());
		}

		if ($submission->markAsHam()) {
			$session->setSuccess(Craft::t('recranet-forms', 'Submission marked as not spam and emails sent.'));
		} else {
			// The flag is cleared; the send failure is recorded in sendError
			$session->setError(Craft::t('recranet-forms', 'Marked as not spam, but the notification email could not be sent.'));
		}

		return $this->redirect($submission->getCpEditUrl());
	}

	/**
	 * CP: resend the owner notification from the detail view. Same effect as
	 * the "Resend notification" element action on the index.
	 */
	public function actionResend(): Response
	{
		$this->requireCpRequest();
		$this->requirePostRequest();
		$this->requirePermission('recranetForms-viewSubmissions');

		$submission = $this->requireCpSubmission();
		$session = Craft::$app->getSession();

		// Spam stays unmailed until a human overrides the flag first
		if ($submission->isSpam) {
			$session->setError(Craft::t('recranet-forms', 'Spam-flagged submissions cannot be resent — use “Not spam” instead.'));

			return $this->redirect($submission->getCpEditUrl());
		}

		if (!$submission->getForm()) {
			$session->setError(Craft::t('recranet-forms', 'The form this submission belongs to no longer exists.'));

			return $this->redirect($submission->getCpEditUrl());
		}

		if ($submission->resendNotification()) {
			$session->setSuccess(Craft::t('recranet-forms', 'Notification email sent.'));
		} else {
			$session->setError(Craft::t('recranet-forms', 'The notification email failed to send — see the log for details.'));
		}

		return $this->redirect($submission->getCpEditUrl());
	}

	/**
	 * CP: add an editor note to a submission from the detail view. With the
	 * "email" flag set the note is also mailed to the form's notification
	 * recipients. Saved without validation: anonymized submissions have
	 * null values for required fields by design, and that must not block a
	 * note.
	 */
	public function actionAddNote(): Response
	{
		$this->requireCpRequest();
		$this->requirePostRequest();
		$this->requirePermission('recranetForms-viewSubmissions');

		$submission = $this->requireCpSubmission();
		$session = Craft::$app->getSession();
		$request = Craft::$app->getRequest();
		$text = trim((string)$request->getBodyParam('text', ''));

		if ($text === '') {
			$session->setError(Craft::t('recranet-forms', 'A note needs some text.'));

			return $this->redirect($submission->getCpEditUrl());
		}

		$user = Craft::$app->getUser()->getIdentity();

		$note = [
			// Name snapshot: a deleted user keeps their name on old notes
			'author' => trim((string)($user?->fullName ?: $user?->username)),
			'authorId' => $user?->id,
			'date' => (new \DateTime())->format(\DateTime::ATOM),
			'text' => $text,
		];

		$submission->notes[] = $note;

		if (!Craft::$app->getElements()->saveElement($submission, runValidation: false)) {
			$session->setError(Craft::t('recranet-forms', 'The note could not be saved.'));

			return $this->redirect($submission->getCpEditUrl());
		}

		$form = $submission->getForm();

		if ($request->getBodyParam('email') && $form) {
			if (Plugin::getInstance()->notifications->sendNote($form, $submission, $note)) {
				$session->setSuccess(Craft::t('recranet-forms', 'Note saved and emailed.'));
			} else {
				// The note itself is safe — only the mail failed
				$session->setError(Craft::t('recranet-forms', 'Note saved, but the email failed to send — see the log for details.'));
			}
		} else {
			$session->setSuccess(Craft::t('recranet-forms', 'Note saved.'));
		}

		return $this->redirect($submission->getCpEditUrl());
	}

	/**
	 * CP: remove a note by its position in the list.
	 */
	public function actionDeleteNote(): Response
	{
		$this->requireCpRequest();
		$this->requirePostRequest();
		$this->requirePermission('recranetForms-viewSubmissions');

		$submission = $this->requireCpSubmission();
		$session = Craft::$app->getSession();
		$index = (int)Craft::$app->getRequest()->getRequiredBodyParam('noteIndex');

		// A stale tab may post an index that no longer exists — just go back
		if (isset($submission->notes[$index])) {
			array_splice($submission->notes, $index, 1);
			Craft::$app->getElements()->saveElement($submission, runValidation: false);
			$session->setSuccess(Craft::t('recranet-forms', 'Note removed.'));
		}

		return $this->redirect($submission->getCpEditUrl());
	}

	/**
	 * Resolve the posted submissionId to a submission (any status) or 404.
	 */
	private function requireCpSubmission(): Submission
	{
		$submissionId = (int)Craft::$app->getRequest()->getRequiredBodyParam('submissionId');
		// siteId('*') — a submission belongs to the site it was made on, and
		// an editor works in whatever site the CP happens to be set to
		$submission = Submission::find()->id($submissionId)->siteId('*')->status(null)->one();

		if (!$submission) {
			throw new NotFoundHttpException('Submission not found.');
		}

		return $submission;
	}
}
