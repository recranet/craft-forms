<?php

namespace recranet\forms\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Json;
use craft\helpers\StringHelper;

/**
 * Phase 0 schema upgrade:
 *
 * 1. Every form field row gets a stable `uid` — the field's identity from
 *    now on. Handles become labels; renaming one no longer orphans data.
 * 2. Existing submissions are remapped: formData keys go handle → uid, and
 *    each submission gets a `snapshot` of its form's field definitions.
 * 3. New metadata columns: spamScore, sendError, incrementalId, token,
 *    sourceUrl, idempotencyKey.
 *
 * The remap resolves handles against the *current* form definition — the
 * best available approximation for pre-uid submissions (handles were the
 * identity up to now, so this is lossless for any form whose handles
 * haven't been reused for different meanings).
 */
class m260806_100000_field_uids_and_metadata extends Migration
{
	public function safeUp(): bool
	{
		// --- 1. New submission columns ---
		$table = '{{%recranetforms_submissions}}';
		$this->addColumn($table, 'snapshot', $this->text()->after('formId'));
		$this->addColumn($table, 'spamScore', $this->decimal(4, 3)->after('isSpam'));
		$this->addColumn($table, 'sendError', $this->text()->after('spamReason'));
		$this->addColumn($table, 'incrementalId', $this->integer()->after('sendError'));
		$this->addColumn($table, 'token', $this->string(32)->after('incrementalId'));
		$this->addColumn($table, 'sourceUrl', $this->string()->after('token'));
		$this->addColumn($table, 'idempotencyKey', $this->string(64)->after('sourceUrl'));

		$this->createIndex(null, $table, ['token'], true);
		$this->createIndex(null, $table, ['idempotencyKey']);

		// --- 2. Assign uids to form field rows ---
		$forms = (new Query())
			->select(['id', 'fields'])
			->from('{{%recranetforms_forms}}')
			->all();

		$fieldsByFormId = [];

		foreach ($forms as $form) {
			$fields = Json::decodeIfJson($form['fields']) ?? [];

			foreach ($fields as &$field) {
				if (empty($field['uid'])) {
					$field['uid'] = StringHelper::UUID();
				}
			}
			unset($field);

			$fieldsByFormId[$form['id']] = $fields;
			$this->update('{{%recranetforms_forms}}', ['fields' => Json::encode($fields)], ['id' => $form['id']]);
		}

		// --- 3. Remap submissions handle → uid, snapshot + backfill metadata ---
		$submissions = (new Query())
			->select(['id', 'formId', 'formData'])
			->from($table)
			->orderBy(['id' => SORT_ASC])
			->all();

		$counters = [];

		foreach ($submissions as $submission) {
			$fields = $fieldsByFormId[$submission['formId']] ?? [];
			$oldData = Json::decodeIfJson($submission['formData']) ?? [];
			$newData = [];

			foreach ($fields as $field) {
				if (array_key_exists($field['handle'], $oldData)) {
					$newData[$field['uid']] = $oldData[$field['handle']];
				}
			}

			$counters[$submission['formId']] = ($counters[$submission['formId']] ?? 0) + 1;

			$this->update($table, [
				'formData' => Json::encode($newData),
				'snapshot' => Json::encode($fields),
				'incrementalId' => $counters[$submission['formId']],
				'token' => StringHelper::randomString(32),
			], ['id' => $submission['id']]);
		}

		return true;
	}

	public function safeDown(): bool
	{
		echo "m260806_100000_field_uids_and_metadata cannot be reverted.\n";

		return false;
	}
}
