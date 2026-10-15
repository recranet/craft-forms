<?php

namespace recranet\forms\migrations;

use craft\db\Migration;

/**
 * Drops the editor-managed sender blocklist (added in 2.12.0, removed in
 * 2.13 together with the blocklist in the plugin settings). Its entries are
 * gone with it; spam protection rests on the captcha, honeypot, throttle
 * and timing checks.
 */
class m261015_100000_drop_blocklist extends Migration
{
	public function safeUp(): bool
	{
		$this->dropTableIfExists('{{%recranetforms_blocklist}}');

		return true;
	}

	public function safeDown(): bool
	{
		echo "m261015_100000_drop_blocklist cannot be reverted.\n";

		return false;
	}
}
