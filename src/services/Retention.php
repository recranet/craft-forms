<?php

namespace recranet\forms\services;

use Craft;
use craft\helpers\Db;
use recranet\forms\elements\Submission;
use recranet\forms\models\Form;
use recranet\forms\Plugin;
use yii\base\Component;

/**
 * Prunes stored submissions past their retention window. Wired into Craft's
 * garbage collection, so it runs whenever GC does; also callable via
 * `php craft recranet-forms/gc/prune` for an explicit run.
 *
 * Retention is resolved per form: a form's own retentionDays override wins,
 * else the plugin-wide Settings::retentionDays applies; an effective value
 * of 0 (or less) means that form's submissions are kept forever. What
 * pruning does is the form's retentionMode: "delete" hard-deletes the whole
 * submission (element, data, uploaded files), "anonymize" keeps the row for
 * statistics but blanks all personal data (see Submission::anonymize()).
 *
 * AVG/GDPR: retention should match what the site's privacy statement
 * promises about how long form data is kept.
 */
class Retention extends Component
{
	/**
	 * @return array{deleted: int, anonymized: int} pruned submissions per mode
	 */
	public function pruneSubmissions(): array
	{
		$defaultDays = Plugin::getInstance()->getSettings()->getRetentionDays();
		$result = ['deleted' => 0, 'anonymized' => 0];

		foreach (Plugin::getInstance()->forms->getAllForms() as $form) {
			// Effective retention: the form's own override, else the plugin default
			$days = $form->getRetentionDaysOverride() ?? $defaultDays;

			// 0 (or less) = keep this form's submissions forever
			if ($days <= 0) {
				continue;
			}

			$cutoff = Db::prepareDateForDb(new \DateTimeImmutable("-{$days} days"));

			if ($form->retentionMode === Form::RETENTION_MODE_ANONYMIZE) {
				$anonymized = $this->anonymizeOldSubmissions($form, $cutoff);
				$result['anonymized'] += $anonymized;

				if ($anonymized > 0) {
					Plugin::info("Retention: anonymized {$anonymized} submission(s) of form \"{$form->handle}\" older than {$days} days.");
				}
			} else {
				$deleted = $this->deleteOldSubmissions($form, $cutoff);
				$result['deleted'] += $deleted;

				if ($deleted > 0) {
					Plugin::info("Retention: deleted {$deleted} submission(s) of form \"{$form->handle}\" older than {$days} days.");
				}
			}
		}

		return $result;
	}

	/**
	 * Hard-delete a form's submissions older than the cutoff.
	 */
	private function deleteOldSubmissions(Form $form, string $cutoff): int
	{
		$ids = Submission::find()
			->formId($form->id)
			->status(null)
			->andWhere(['<', 'elements.dateCreated', $cutoff])
			->ids();

		$deleted = 0;

		foreach ($ids as $id) {
			// Hard delete: retention exists to actually remove personal data,
			// so soft-deleted trash rows would defeat the purpose (this also
			// removes uploaded assets, see Submission::afterDelete())
			if (Craft::$app->getElements()->deleteElementById($id, hardDelete: true)) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Anonymize a form's submissions older than the cutoff: the element row
	 * survives (counts, dates and reference numbers stay usable), the
	 * personal data does not. Already-anonymized rows are skipped, so a
	 * submission is processed exactly once.
	 */
	private function anonymizeOldSubmissions(Form $form, string $cutoff): int
	{
		$submissions = Submission::find()
			->formId($form->id)
			->status(null)
			->andWhere(['<', 'elements.dateCreated', $cutoff])
			->andWhere(['recranetforms_submissions.anonymizedAt' => null])
			->all();

		$anonymized = 0;

		foreach ($submissions as $submission) {
			/** @var Submission $submission */
			if ($submission->anonymize()) {
				$anonymized++;
			}
		}

		return $anonymized;
	}
}
