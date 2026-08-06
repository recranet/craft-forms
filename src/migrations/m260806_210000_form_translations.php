<?php

namespace recranet\forms\migrations;

use craft\db\Migration;

/**
 * Per-site translations of form content.
 *
 * Form definitions are content, not project config, and editors type the
 * labels themselves — so the translations belong in the database too, next
 * to the source form. One row per (form, site); the JSON holds only the
 * strings that differ from the source, keyed the same way the builder posts
 * them (see services/FormTranslations).
 */
class m260806_210000_form_translations extends Migration
{
	public function safeUp(): bool
	{
		if ($this->db->tableExists('{{%recranetforms_form_translations}}')) {
			return true;
		}

		$this->createTable('{{%recranetforms_form_translations}}', [
			'id' => $this->primaryKey(),
			'formId' => $this->integer()->notNull(),
			'siteId' => $this->integer()->notNull(),
			'translations' => $this->text(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		// One row per form + site; both parents cascade so nothing is orphaned
		$this->createIndex(null, '{{%recranetforms_form_translations}}', ['formId', 'siteId'], true);
		$this->addForeignKey(null, '{{%recranetforms_form_translations}}', ['formId'], '{{%recranetforms_forms}}', ['id'], 'CASCADE');
		$this->addForeignKey(null, '{{%recranetforms_form_translations}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists('{{%recranetforms_form_translations}}');

		return true;
	}
}
