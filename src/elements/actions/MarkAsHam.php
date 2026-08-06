<?php

namespace recranet\forms\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use recranet\forms\elements\Submission;

/**
 * "Not spam" element action: false-positive recovery for spam-flagged
 * submissions. Clears the spam flag (keeping the original reason prefixed
 * with "Overridden: " as an audit trail) and sends the notification +
 * confirmation emails that were skipped when the submission was flagged.
 *
 * Only enabled when every selected row is spam-flagged — the `data-spam`
 * attribute comes from Submission::htmlAttributes().
 */
class MarkAsHam extends ElementAction
{
	public function getTriggerLabel(): string
	{
		return Craft::t('recranet-forms', 'Not spam');
	}

	public function getConfirmationMessage(): ?string
	{
		return Craft::t('recranet-forms', 'Mark the selected submissions as not spam and send the emails that were skipped?');
	}

	public function getTriggerHtml(): ?string
	{
		// Enable the action only when every selected row is spam-flagged
		Craft::$app->getView()->registerJsWithVars(fn($type) => <<<JS
(() => {
	new Craft.ElementActionTrigger({
		type: $type,
		validateSelection: (selectedItems) => {
			for (let i = 0; i < selectedItems.length; i++) {
				if (!Garnish.hasAttr(selectedItems.eq(i).find('.element'), 'data-spam')) {
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
			// The trigger already limits selection to spam rows; skip anything
			// else that slips through (e.g. a stale index)
			if (!$submission->isSpam) {
				continue;
			}

			if (!$submission->markAsHam()) {
				$failed++;
			}
		}

		if ($failed) {
			// A failure here is either a deleted form or a mail transport
			// problem — the spam flag itself may already be cleared, and a
			// send failure is recorded on the submission (status "failed")
			$this->setMessage(Craft::t('recranet-forms', 'Some submissions could not be processed — check their status and the logs.'));

			return false;
		}

		$this->setMessage(Craft::t('recranet-forms', 'Submissions marked as not spam.'));

		return true;
	}
}
