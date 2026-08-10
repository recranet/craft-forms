<?php

namespace recranet\forms\migrations;

use craft\db\Migration;

/**
 * Editor notes on a submission: a JSON list of {author, authorId, date,
 * text} rows, written from the CP detail view. Notes are editor content
 * about a submission, so anonymization clears them along with the rest —
 * they may quote the personal data the pruning removed.
 */
class m260810_100000_submission_notes extends Migration
{
	public function safeUp(): bool
	{
		$table = '{{%recranetforms_submissions}}';

		if (!$this->db->columnExists($table, 'notes')) {
			$this->addColumn($table, 'notes', $this->text()->after('paymentAmount'));
		}

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropColumn('{{%recranetforms_submissions}}', 'notes');

		return true;
	}
}
