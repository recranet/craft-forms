<?php

namespace recranet\forms\migrations;

use craft\db\Migration;

/**
 * Created the editor-managed sender blocklist table (2.12.0). The blocklist
 * was removed in 2.13 and m261015_100000_drop_blocklist drops the table, so
 * this is a no-op now: an install still below it has nothing to create.
 * The class stays so Craft's migration history keeps resolving.
 */
class m260810_200000_blocklist extends Migration
{
	public function safeUp(): bool
	{
		return true;
	}

	public function safeDown(): bool
	{
		return true;
	}
}
