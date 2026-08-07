<?php

namespace recranet\forms\migrations;

use craft\db\Migration;

/**
 * Payment columns on the submissions table.
 *
 * `paymentStatus` is null for submissions that never needed a payment;
 * pending/paid/failed/expired/canceled otherwise (Mollie's terminal states
 * map onto these). `paymentAmount` is stored in whole cents — never floats —
 * and `paymentId` is the provider's payment id, used by the webhook and the
 * return poll to look the submission up.
 */
class m260807_100000_payments extends Migration
{
	public function safeUp(): bool
	{
		$table = '{{%recranetforms_submissions}}';

		if (!$this->db->columnExists($table, 'paymentStatus')) {
			$this->addColumn($table, 'paymentStatus', $this->string(16)->after('anonymizedAt'));
			$this->addColumn($table, 'paymentId', $this->string(64)->after('paymentStatus'));
			$this->addColumn($table, 'paymentAmount', $this->integer()->after('paymentId'));
			$this->createIndex(null, $table, ['paymentId']);
		}

		return true;
	}

	public function safeDown(): bool
	{
		$table = '{{%recranetforms_submissions}}';
		$this->dropColumn($table, 'paymentAmount');
		$this->dropColumn($table, 'paymentId');
		$this->dropColumn($table, 'paymentStatus');

		return true;
	}
}
