<?php

namespace elloro\forms\services;

use Craft;
use craft\web\View;
use elloro\forms\elements\Submission;
use elloro\forms\models\Form;
use yii\base\Component;

/**
 * Sends the notification email (to the site owner) and optional
 * confirmation email (to the submitter). Send failures are logged and
 * reported — the submission itself is already saved at this point, so a
 * mail problem never loses data.
 */
class Notifications extends Component
{
	/**
	 * Send the owner notification. Returns false on failure (logged).
	 */
	public function sendNotification(Form $form, Submission $submission): bool
	{
		$recipients = $form->getRecipientList();

		if (!$recipients) {
			Craft::warning("Form \"{$form->handle}\": no notification recipients configured and no system email set.", __METHOD__);

			return false;
		}

		$html = $this->renderEmail('elloro-forms/_emails/notification', $form, $submission);

		$message = Craft::$app->getMailer()->compose()
			->setTo($recipients)
			->setSubject($form->subject ?: "New submission: {$form->name}")
			->setHtmlBody($html);

		// Reply-to the submitter when the form has an email field
		$emailHandle = $form->getEmailFieldHandle();
		$submitterEmail = $emailHandle ? ($submission->content[$emailHandle] ?? null) : null;

		if ($submitterEmail) {
			$message->setReplyTo($submitterEmail);
		}

		if (!$message->send()) {
			Craft::error("Form \"{$form->handle}\": notification email failed to send.", __METHOD__);

			return false;
		}

		return true;
	}

	/**
	 * Send the confirmation email to the submitter, when enabled and an
	 * email address was submitted.
	 */
	public function sendConfirmation(Form $form, Submission $submission): bool
	{
		if (!$form->sendConfirmation) {
			return true;
		}

		$emailHandle = $form->getEmailFieldHandle();
		$submitterEmail = $emailHandle ? ($submission->content[$emailHandle] ?? null) : null;

		if (!$submitterEmail) {
			return true;
		}

		$html = $this->renderEmail('elloro-forms/_emails/confirmation', $form, $submission);

		$sent = Craft::$app->getMailer()->compose()
			->setTo($submitterEmail)
			->setSubject($form->confirmationSubject ?: $form->name)
			->setHtmlBody($html)
			->send();

		if (!$sent) {
			Craft::error("Form \"{$form->handle}\": confirmation email failed to send.", __METHOD__);
		}

		return $sent;
	}

	/**
	 * Render an email template. Projects can override these by placing
	 * templates at templates/elloro-forms/_emails/*.twig (site templates
	 * take precedence over the plugin's CP-mode fallbacks).
	 */
	private function renderEmail(string $template, Form $form, Submission $submission): string
	{
		$view = Craft::$app->getView();
		$variables = [
			'form' => $form,
			'submission' => $submission,
		];

		// Prefer a site template override, fall back to the plugin template
		if ($view->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)) {
			return $view->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE);
		}

		return $view->renderTemplate($template, $variables, View::TEMPLATE_MODE_CP);
	}
}
