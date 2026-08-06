<?php

namespace recranet\forms\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use recranet\forms\elements\Submission;

/**
 * "Resend notification" element action: resends the owner notification
 * email for non-spam submissions — typically after a transport failure
 * (status "failed"), but also usable to re-deliver a lost mail. Success
 * clears `sendError`; failure records it (status "failed").
 *
 * Disabled for spam-flagged rows (use "Not spam" for those) — the
 * `data-spam` attribute comes from Submission::htmlAttributes().
 */
class ResendNotification extends ElementAction
{
	public function getTriggerLabel(): string
	{
		return Craft::t('recranet-forms', 'Resend notification');
	}

	public function getConfirmationMessage(): ?string
	{
		return Craft::t('recranet-forms', 'Resend the notification email for the selected submissions?');
	}

	public function getTriggerHtml(): ?string
	{
		// Enable the action only when no selected row is spam-flagged
		Craft::$app->getView()->registerJsWithVars(fn($type) => <<<JS
(() => {
	new Craft.ElementActionTrigger({
		type: $type,
		validateSelection: (selectedItems) => {
			for (let i = 0; i < selectedItems.length; i++) {
				if (Garnish.hasAttr(selectedItems.eq(i).find('.element'), 'data-spam')) {
					return false;
				}
			}
			return true;
		},
	});
})();
JS, [static::class]);

		return null;
	}

	public function performAction(ElementQueryInterface $query): bool
	{
		// Defense in depth — the submissions index already requires this
		if (!Craft::$app->getUser()->checkPermission('recranetForms-viewSubmissions')) {
			return false;
		}

		$failed = 0;

		/** @var Submission $submission */
		foreach (Db::each($query) as $submission) {
			// Spam rows are excluded by the trigger; never mail those here
			if ($submission->isSpam) {
				continue;
			}

			if (!$submission->resendNotification()) {
				$failed++;
			}
		}

		if ($failed) {
			// Send failures are recorded per submission (status "failed"),
			// so nothing is lost — the transport error is in the log
			$this->setMessage(Craft::t('recranet-forms', 'Some notification emails failed to send — check the submission status and the logs.'));

			return false;
		}

		$this->setMessage(Craft::t('recranet-forms', 'Notification emails sent.'));

		return true;
	}
}
