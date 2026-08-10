<?php

namespace recranet\forms\migrations;

use craft\db\Migration;
use recranet\forms\elements\Submission;

/**
 * Install migration: forms table, submissions table (extends elements) and
 * the per-site form translations table.
 *
 * Fresh installs run ONLY this migration — Craft marks the numbered ones as
 * applied via schemaVersion. Every table a numbered migration creates must
 * therefore also be created here, or new installs miss it.
 */
class Install extends Migration
{
	public function safeUp(): bool
	{
		$this->createTable('{{%recranetforms_forms}}', [
			'id' => $this->primaryKey(),
			'name' => $this->string()->notNull(),
			'handle' => $this->string()->notNull(),
			'fields' => $this->text(),
			'settings' => $this->text(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, '{{%recranetforms_forms}}', ['handle'], true);

		$this->createTable('{{%recranetforms_submissions}}', [
			'id' => $this->integer()->notNull(),
			'formId' => $this->integer()->notNull(),
			'snapshot' => $this->text(),
			'spamScore' => $this->decimal(4, 3),
			'sendError' => $this->text(),
			'incrementalId' => $this->integer(),
			'token' => $this->string(32),
			'sourceUrl' => $this->string(),
			'idempotencyKey' => $this->string(64),
			// Set when retention pruning anonymized this submission (form
			// retention mode "anonymize"): row kept, personal data blanked
			'anonymizedAt' => $this->dateTime(),
			// Payments (mirrors m260807_100000): null = no payment involved;
			// amount in whole cents, id = the provider's payment id
			'paymentStatus' => $this->string(16),
			'paymentId' => $this->string(64),
			'paymentAmount' => $this->integer(),
			// Editor notes (mirrors m260810_100000): JSON rows of
			// {author, authorId, date, text}, cleared on anonymize
			'notes' => $this->text(),
			'formData' => $this->text(),
			'isSpam' => $this->boolean()->notNull()->defaultValue(false),
			'spamReason' => $this->string(),
			'PRIMARY KEY([[id]])',
		]);

		// Submission rows follow their element row (soft-delete aware via elements table)
		$this->addForeignKey(null, '{{%recranetforms_submissions}}', ['id'], '{{%elements}}', ['id'], 'CASCADE');
		$this->addForeignKey(null, '{{%recranetforms_submissions}}', ['formId'], '{{%recranetforms_forms}}', ['id'], 'CASCADE');
		$this->createIndex(null, '{{%recranetforms_submissions}}', ['formId']);
		$this->createIndex(null, '{{%recranetforms_submissions}}', ['isSpam']);
		$this->createIndex(null, '{{%recranetforms_submissions}}', ['token'], true);
		$this->createIndex(null, '{{%recranetforms_submissions}}', ['idempotencyKey']);
		$this->createIndex(null, '{{%recranetforms_submissions}}', ['paymentId']);

		// Per-site translations of form content (mirrors m260806_210000):
		// one row per (form, site), JSON of only the strings that differ
		// from the source form
		$this->createTable('{{%recranetforms_form_translations}}', [
			'id' => $this->primaryKey(),
			'formId' => $this->integer()->notNull(),
			'siteId' => $this->integer()->notNull(),
			'translations' => $this->text(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, '{{%recranetforms_form_translations}}', ['formId', 'siteId'], true);
		$this->addForeignKey(null, '{{%recranetforms_form_translations}}', ['formId'], '{{%recranetforms_forms}}', ['id'], 'CASCADE');
		$this->addForeignKey(null, '{{%recranetforms_form_translations}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');

		// Editor-managed sender blocklist (mirrors m260810_200000): the
		// production-editable half of Settings::$blocklist, which is project
		// config and therefore read-only where allowAdminChanges is off
		$this->createTable('{{%recranetforms_blocklist}}', [
			'id' => $this->primaryKey(),
			'pattern' => $this->string()->notNull(),
			'note' => $this->string(),
			'addedBy' => $this->integer(),
			'submissionId' => $this->integer(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, '{{%recranetforms_blocklist}}', ['pattern'], true);
		$this->addForeignKey(null, '{{%recranetforms_blocklist}}', ['addedBy'], '{{%users}}', ['id'], 'SET NULL');
		$this->addForeignKey(null, '{{%recranetforms_blocklist}}', ['submissionId'], '{{%elements}}', ['id'], 'SET NULL');

		return true;
	}

	public function safeDown(): bool
	{
		// Remove submission elements before dropping their data table
		$this->delete('{{%elements}}', ['type' => Submission::class]);
		$this->dropTableIfExists('{{%recranetforms_blocklist}}');
		$this->dropTableIfExists('{{%recranetforms_form_translations}}');
		$this->dropTableIfExists('{{%recranetforms_submissions}}');
		$this->dropTableIfExists('{{%recranetforms_forms}}');

		return true;
	}
}
