<?php

namespace recranet\forms\migrations;

use craft\db\Migration;

/**
 * Adds the `anonymizedAt` column to the submissions table.
 *
 * Set when retention pruning anonymizes a submission (a form's retention
 * mode is "anonymize"): the element row survives for statistics, but every
 * formData value, the self-service token, the source URL and the uploaded
 * files are gone. The timestamp both marks the row as processed (so pruning
 * never re-anonymizes it) and tells editors in the CP when the personal
 * data was removed.
 */
class m260806_200000_anonymized_at extends Migration
{
	public function safeUp(): bool
	{
		$table = '{{%recranetforms_submissions}}';

		if (!$this->db->columnExists($table, 'anonymizedAt')) {
			$this->addColumn($table, 'anonymizedAt', $this->dateTime()->after('idempotencyKey'));
		}

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropColumn('{{%recranetforms_submissions}}', 'anonymizedAt');

		return true;
	}
}
