<?php

namespace recranet\forms\records;

use craft\db\ActiveRecord;

/**
 * ActiveRecord for the recranetforms_forms table.
 *
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $fields JSON field definition rows
 * @property string $settings JSON notification settings
 * @property string $uid
 */
class FormRecord extends ActiveRecord
{
	public static function tableName(): string
	{
		return '{{%recranetforms_forms}}';
	}
}
