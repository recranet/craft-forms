<?php

namespace recranet\forms\migrations;

use craft\db\Migration;

/**
 * Editor-managed sender blocklist.
 *
 * The plugin-wide Settings::$blocklist lives in project config, which is
 * read-only wherever `allowAdminChanges` is off — i.e. exactly the
 * production environments where editors triage spam. This table is the
 * second half of that list: same entry shapes, same matching, but content
 * instead of config, so blocking a sender needs no deploy and no admin.
 *
 * `submissionId` records which submission an entry came from and is nulled
 * (not cascaded) when that submission goes away — the block must survive
 * retention pruning of the message that prompted it.
 *
 * The table name is spelled out literally instead of via a variable: the
 * Install parity test greps for `createTable('{{%…}}')`, and a variable
 * would slip past the guard that keeps fresh installs in sync.
 */
class m260810_200000_blocklist extends Migration
{
	public function safeUp(): bool
	{
		if ($this->db->tableExists('{{%recranetforms_blocklist}}')) {
			return true;
		}

		$this->createTable('{{%recranetforms_blocklist}}', [
			'id' => $this->primaryKey(),
			// Entry shapes match Settings::$blocklist: full address, @domain
			// suffix, local-part prefix, or IP prefix
			'pattern' => $this->string()->notNull(),
			'note' => $this->string(),
			'addedBy' => $this->integer(),
			'submissionId' => $this->integer(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		// One row per pattern: blocking the same sender twice is a no-op
		$this->createIndex(null, '{{%recranetforms_blocklist}}', ['pattern'], true);
		$this->addForeignKey(null, '{{%recranetforms_blocklist}}', ['addedBy'], '{{%users}}', ['id'], 'SET NULL');
		$this->addForeignKey(null, '{{%recranetforms_blocklist}}', ['submissionId'], '{{%elements}}', ['id'], 'SET NULL');

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists('{{%recranetforms_blocklist}}');

		return true;
	}
}
