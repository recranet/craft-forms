<?php

namespace recranet\forms\migrations;

use craft\db\Migration;
use recranet\forms\elements\Submission;

/**
 * Install migration: forms table + submissions table (extends elements).
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

		return true;
	}

	public function safeDown(): bool
	{
		// Remove submission elements before dropping their data table
		$this->delete('{{%elements}}', ['type' => Submission::class]);
		$this->dropTableIfExists('{{%recranetforms_submissions}}');
		$this->dropTableIfExists('{{%recranetforms_forms}}');

		return true;
	}
}
