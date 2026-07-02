<?php

namespace elloro\forms\controllers;

use Craft;
use craft\web\Controller;
use elloro\forms\elements\Submission;
use elloro\forms\models\Form;
use elloro\forms\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Handles the front-end submit action and the CP submission screens.
 *
 * Submit flow:
 * 1. Validate submitted values against the form's field definitions.
 * 2. Honeypot filled → save flagged as spam, pretend success (don't tip off bots).
 * 3. reCAPTCHA verdict:
 *    - pass → continue
 *    - spam → save flagged, pretend success
 *    - error (broken config/infra) → NEVER treated as spam: fail-open accepts
 *      and flags the submission + logs a warning; fail-closed shows the
 *      visitor a real error.
 * 4. Save submission, send notification + optional confirmation.
 */
class SubmissionsController extends Controller
{
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

		[$content, $errors] = $this->validateContent($form, (array)$request->getBodyParam('fields', []));

		if ($errors) {
			// Re-render the page with errors and the submitted values
			Craft::$app->getUrlManager()->setRouteParams([
				'formErrors' => $errors,
				'formContent' => $content,
				'formHandle' => $form->handle,
			]);

			return null;
		}

		$submission = new Submission([
			'formId' => $form->id,
			'content' => $content,
		]);

		// Honeypot: a filled hidden field is a hard spam signal
		if (trim((string)$request->getBodyParam($settings->honeypotName, '')) !== '') {
			$submission->isSpam = true;
			$submission->spamReason = 'Honeypot field was filled';
		} else {
			$result = Plugin::getInstance()->recaptcha->verify($request->getBodyParam('g-recaptcha-response'));

			if ($result->isSpam()) {
				$submission->isSpam = true;
				$submission->spamReason = $result->reason;
			} elseif ($result->isError()) {
				// Config/infra problem — this is our bug, not the visitor's spam
				Craft::warning("Form \"{$form->handle}\": {$result->reason}", __METHOD__);

				if (!$settings->recaptchaFailOpen) {
					Craft::$app->getSession()->setError(Craft::t('elloro-forms', 'Something went wrong verifying your submission. Please try again later.'));
					Craft::$app->getUrlManager()->setRouteParams([
						'formContent' => $content,
						'formHandle' => $form->handle,
					]);

					return null;
				}

				// Fail open: accept but record why, so it's diagnosable in the CP
				$submission->spamReason = 'Accepted despite reCAPTCHA error: ' . $result->reason;
			}
		}

		if (!Craft::$app->getElements()->saveElement($submission)) {
			Craft::error("Form \"{$form->handle}\": failed to save submission: " . implode('; ', $submission->getFirstErrors()), __METHOD__);
			Craft::$app->getSession()->setError(Craft::t('elloro-forms', 'Something went wrong. Please try again later.'));

			return null;
		}

		// Spam submissions are stored but never emailed; visitor still sees success
		if (!$submission->isSpam) {
			Plugin::getInstance()->notifications->sendNotification($form, $submission);
			Plugin::getInstance()->notifications->sendConfirmation($form, $submission);
		}

		Craft::$app->getSession()->setSuccess(Craft::t('elloro-forms', 'Thank you! Your message has been sent.'));

		return $this->redirectToPostedUrl($submission);
	}

	/**
	 * Validate posted values against the form's field definitions.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, string[]>} [content, errors]
	 */
	private function validateContent(Form $form, array $posted): array
	{
		$content = [];
		$errors = [];

		foreach ($form->fields as $field) {
			$handle = $field['handle'];
			$value = $posted[$handle] ?? null;
			$value = is_string($value) ? trim($value) : $value;

			if ($field['type'] === 'checkbox') {
				$value = (bool)$value;
			}

			if (!empty($field['required']) && ($value === null || $value === '' || $value === false)) {
				$errors[$handle][] = Craft::t('elloro-forms', '{label} is required.', ['label' => $field['label']]);
			}

			if ($field['type'] === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
				$errors[$handle][] = Craft::t('elloro-forms', '{label} must be a valid email address.', ['label' => $field['label']]);
			}

			// Reject values that aren't one of the configured select options
			if ($field['type'] === 'select' && $value) {
				$options = array_map('trim', explode(',', $field['options'] ?? ''));

				if (!in_array($value, $options, true)) {
					$errors[$handle][] = Craft::t('elloro-forms', '{label} has an invalid value.', ['label' => $field['label']]);
				}
			}

			$content[$handle] = $value;
		}

		return [$content, $errors];
	}

	/**
	 * CP: submissions element index.
	 */
	public function actionIndex(): Response
	{
		$this->requireCpRequest();
		$this->requirePermission('accessPlugin-elloro-forms');

		return $this->renderTemplate('elloro-forms/submissions/index');
	}

	/**
	 * CP: single submission detail view.
	 */
	public function actionView(int $submissionId): Response
	{
		$this->requireCpRequest();
		$this->requirePermission('accessPlugin-elloro-forms');

		$submission = Submission::find()->id($submissionId)->status(null)->one();

		if (!$submission) {
			throw new NotFoundHttpException('Submission not found.');
		}

		return $this->renderTemplate('elloro-forms/submissions/view', [
			'submission' => $submission,
			'form' => $submission->getForm(),
		]);
	}
}
