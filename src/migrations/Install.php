<?php

namespace elloro\forms\migrations;

use craft\db\Migration;
use elloro\forms\elements\Submission;

/**
 * Install migration: forms table + submissions table (extends elements).
 */
class Install extends Migration
{
	public function safeUp(): bool
	{
		$this->createTable('{{%elloroforms_forms}}', [
			'id' => $this->primaryKey(),
			'name' => $this->string()->notNull(),
			'handle' => $this->string()->notNull(),
			'fields' => $this->text(),
			'settings' => $this->text(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, '{{%elloroforms_forms}}', ['handle'], true);

		$this->createTable('{{%elloroforms_submissions}}', [
			'id' => $this->integer()->notNull(),
			'formId' => $this->integer()->notNull(),
			'content' => $this->text(),
			'isSpam' => $this->boolean()->notNull()->defaultValue(false),
			'spamReason' => $this->string(),
			'PRIMARY KEY([[id]])',
		]);

		// Submission rows follow their element row (soft-delete aware via elements table)
		$this->addForeignKey(null, '{{%elloroforms_submissions}}', ['id'], '{{%elements}}', ['id'], 'CASCADE');
		$this->addForeignKey(null, '{{%elloroforms_submissions}}', ['formId'], '{{%elloroforms_forms}}', ['id'], 'CASCADE');
		$this->createIndex(null, '{{%elloroforms_submissions}}', ['formId']);
		$this->createIndex(null, '{{%elloroforms_submissions}}', ['isSpam']);

		return true;
	}

	public function safeDown(): bool
	{
		// Remove submission elements before dropping their data table
		$this->delete('{{%elements}}', ['type' => Submission::class]);
		$this->dropTableIfExists('{{%elloroforms_submissions}}');
		$this->dropTableIfExists('{{%elloroforms_forms}}');

		return true;
	}
}
