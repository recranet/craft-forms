<?php

namespace recranet\forms\services;

use Craft;
use craft\web\View;
use recranet\forms\elements\Submission;
use recranet\forms\models\Form;
use recranet\forms\Plugin;
use yii\base\Component;

/**
 * Sends the notification email (to the site owner) and optional
 * confirmation email (to the submitter). Send failures are logged and
 * reported — the submission itself is already saved at this point, so a
 * mail problem never loses data.
 *
 * Subjects and recipients support merge tags via Craft's object template
 * syntax: submitted values by field handle ({onderwerp}) plus {formName},
 * {ref}, {sourceUrl} and {date}. A broken tag never breaks the mail — the
 * raw string is used instead and a warning is logged.
 */
class Notifications extends Component
{
	/**
	 * Send the owner notification. Returns false on failure (logged).
	 */
	public function sendNotification(Form $form, Submission $submission): bool
	{
		$recipients = $this->resolveRecipients($form, $submission);

		if (!$recipients) {
			Craft::warning("Form \"{$form->handle}\": no notification recipients configured and no system email set.", __METHOD__);

			return false;
		}

		$html = $this->renderEmail('recranet-forms/_emails/notification', $form, $submission, $form->notificationTemplate);

		// Subject supports merge tags, e.g. "Aanvraag {onderwerp} — #{ref}"
		$subject = $this->renderTemplateString($form->subject ?: "New submission: {$form->name}", $form, $submission);

		$message = Craft::$app->getMailer()->compose()
			->setTo($recipients)
			->setSubject($subject)
			->setHtmlBody($html);

		// Reply-to the submitter when the form has an email field
		$emailHandle = $form->getEmailFieldHandle();
		$submitterEmail = $emailHandle ? $submission->value($emailHandle) : null;

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
		$submitterEmail = $emailHandle ? $submission->value($emailHandle) : null;

		if (!$submitterEmail) {
			return true;
		}

		$html = $this->renderEmail('recranet-forms/_emails/confirmation', $form, $submission, $form->confirmationTemplate);

		// Confirmation subject supports the same merge tags as the notification
		$subject = $this->renderTemplateString($form->confirmationSubject ?: $form->name, $form, $submission);

		$sent = Craft::$app->getMailer()->compose()
			->setTo($submitterEmail)
			->setSubject($subject)
			->setHtmlBody($html)
			->send();

		if (!$sent) {
			Craft::error("Form \"{$form->handle}\": confirmation email failed to send.", __METHOD__);
		}

		return $sent;
	}

	/**
	 * Render an email template. Resolution order:
	 *
	 * 1. the per-form template override (a site template path picked in the
	 *    form's mail settings), when set and existing
	 * 2. the site-wide override at templates/recranet-forms/_emails/*.twig
	 * 3. the plugin's built-in template
	 *
	 * Templates receive `form`, `submission`, plus the editor-managed
	 * `intro` (notification) and `bodyText` (confirmation) — both rendered
	 * through the merge-tag pipeline, so `{naam}` works inside them too.
	 */
	private function renderEmail(string $template, Form $form, Submission $submission, string $formTemplate = ''): string
	{
		$view = Craft::$app->getView();
		$variables = [
			'form' => $form,
			'submission' => $submission,
			'intro' => $form->notificationIntro ? $this->renderTemplateString($form->notificationIntro, $form, $submission) : '',
			'bodyText' => $form->confirmationBody ? $this->renderTemplateString($form->confirmationBody, $form, $submission) : '',
		];

		// Per-form override wins; a broken path logs and falls through so the
		// mail still goes out with the default template
		if ($formTemplate !== '') {
			if ($view->doesTemplateExist($formTemplate, View::TEMPLATE_MODE_SITE)) {
				return $view->renderTemplate($formTemplate, $variables, View::TEMPLATE_MODE_SITE);
			}

			Plugin::error("Form \"{$form->handle}\": mail template \"{$formTemplate}\" not found — falling back to the default.");
		}

		// Prefer a site template override, fall back to the plugin template
		if ($view->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)) {
			return $view->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE);
		}

		return $view->renderTemplate($template, $variables, View::TEMPLATE_MODE_CP);
	}

	/**
	 * Render a template string (subject, recipients) against a submission
	 * using Craft's object template syntax — the same {tag} rendering entry
	 * URI formats use, so no invented syntax to maintain.
	 *
	 * Available tags: the submitted values keyed by field handle (resolved
	 * via the snapshot — formData itself is uid-keyed), plus {formName},
	 * {ref} (per-form number), {sourceUrl} and {date} (submission date).
	 *
	 * On a Twig error in the editor's string the raw string is returned
	 * and a warning is logged — a bad merge tag must never break the mail.
	 */
	private function renderTemplateString(string $template, Form $form, Submission $submission): string
	{
		// Fast path: no braces means no merge tags to render
		if (!str_contains($template, '{')) {
			return $template;
		}

		$variables = [];

		// Submitted values keyed by handle; array values (e.g. checkbox
		// fields) are joined so they read naturally inline, and empty
		// fields become '' so their tags render blank instead of erroring
		foreach ($submission->getValues() as $row) {
			$value = $row['value'];
			$variables[$row['handle']] = is_array($value) ? implode(', ', $value) : ($value ?? '');
		}

		// Metadata tags, set last so they win over a clashing field handle
		$variables['formName'] = $form->name;
		$variables['ref'] = $submission->incrementalId;
		$variables['sourceUrl'] = $submission->sourceUrl;
		$variables['date'] = $submission->dateCreated
			? Craft::$app->getFormatter()->asDatetime($submission->dateCreated, 'short')
			: '';
		// AVG self-service link: the submitter can view/erase their own
		// submission. Empty when nothing was stored (mail-only mode/rejects).
		$variables['selfServiceUrl'] = $submission->token
			? \craft\helpers\UrlHelper::siteUrl('recranet-forms/submission/' . $submission->token)
			: '';

		try {
			return Craft::$app->getView()->renderObjectTemplate($template, $submission, $variables);
		} catch (\Throwable $e) {
			Plugin::error("Form \"{$form->handle}\": could not render merge tags in \"{$template}\": {$e->getMessage()}");

			return $template;
		}
	}

	/**
	 * Resolve the notification recipients for a submission. Merge tags in
	 * the recipients setting ("{afdeling}@example.com") enable lightweight
	 * routing, so the string is rendered BEFORE it is split — a single tag
	 * may even expand to several comma-separated addresses.
	 */
	private function resolveRecipients(Form $form, Submission $submission): array
	{
		$rendered = $this->renderTemplateString($form->recipients, $form, $submission);
		$recipients = array_filter(array_map('trim', explode(',', $rendered)));

		// Nothing configured (or everything rendered away): fall back to
		// the model's list, which resolves the system email
		if (!$recipients) {
			$recipients = $form->getRecipientList();
		}

		// Drop anything that didn't render into a valid address — one bad
		// merge tag must not make the whole mail fail at transport level
		return array_values(array_filter($recipients, function (string $recipient) use ($form): bool {
			if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
				return true;
			}

			Plugin::error("Form \"{$form->handle}\": dropping invalid notification recipient \"{$recipient}\".");

			return false;
		}));
	}
}
