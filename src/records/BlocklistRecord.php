<?php

namespace recranet\forms\records;

use craft\db\ActiveRecord;

/**
 * ActiveRecord for the recranetforms_blocklist table: the editor-managed
 * half of the sender blocklist (the other half is Settings::$blocklist,
 * which is project config and read-only on production).
 *
 * @property int $id
 * @property string $pattern Full address, @domain suffix, local-part prefix or IP prefix
 * @property string|null $note Why this sender was blocked
 * @property int|null $addedBy User id, null once that user is deleted
 * @property int|null $submissionId Submission it was blocked from, null once that is gone
 * @property string $uid
 */
class BlocklistRecord extends ActiveRecord
{
	public static function tableName(): string
	{
		return '{{%recranetforms_blocklist}}';
	}
}
