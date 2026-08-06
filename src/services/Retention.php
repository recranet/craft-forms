<?php

namespace recranet\forms\services;

use Craft;
use craft\helpers\Db;
use recranet\forms\elements\Submission;
use recranet\forms\Plugin;
use yii\base\Component;

/**
 * Deletes stored submissions past the configured retention window
 * (Settings::retentionDays). Wired into Craft's garbage collection, so it
 * runs whenever GC does; also callable via
 * `php craft recranet-forms/gc/prune` for an explicit run.
 *
 * AVG/GDPR: retention should match what the site's privacy statement
 * promises about how long form data is kept.
 */
class Retention extends Component
{
	/**
	 * @return int number of deleted submissions
	 */
	public function pruneSubmissions(): int
	{
		$days = Plugin::getInstance()->getSettings()->getRetentionDays();

		if ($days <= 0) {
			return 0;
		}

		$cutoff = Db::prepareDateForDb(new \DateTimeImmutable("-{$days} days"));

		$ids = Submission::find()
			->status(null)
			->andWhere(['<', 'elements.dateCreated', $cutoff])
			->ids();

		$deleted = 0;

		foreach ($ids as $id) {
			// Hard delete: retention exists to actually remove personal data,
			// so soft-deleted trash rows would defeat the purpose
			if (Craft::$app->getElements()->deleteElementById($id, hardDelete: true)) {
				$deleted++;
			}
		}

		if ($deleted > 0) {
			Plugin::info("Retention: deleted {$deleted} submission(s) older than {$days} days.");
		}

		return $deleted;
	}
}
