<?php

namespace recranet\forms\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use recranet\forms\elements\Submission;

/**
 * Element query for submissions. Adds formId/isSpam filtering and maps the
 * plugin table columns onto the element.
 */
class SubmissionQuery extends ElementQuery
{
	public mixed $formId = null;
	public ?bool $isSpam = null;

	public function formId(mixed $value): static
	{
		$this->formId = $value;

		return $this;
	}

	public function isSpam(?bool $value): static
	{
		$this->isSpam = $value;

		return $this;
	}

	/**
	 * Map the custom statuses onto the isSpam/sendError/paymentStatus
	 * columns. Must stay in parity with Submission::getStatus(): spam wins,
	 * then an unfinished payment, then the send error.
	 */
	protected function statusCondition(string $status): mixed
	{
		// "The payment is settled or was never involved" — used by sent/failed
		$paymentSettled = [
			'or',
			['recranetforms_submissions.paymentStatus' => null],
			['recranetforms_submissions.paymentStatus' => Submission::PAYMENT_PAID],
		];

		return match ($status) {
			Submission::STATUS_SENT => [
				'and',
				['recranetforms_submissions.isSpam' => false],
				['recranetforms_submissions.sendError' => null],
				$paymentSettled,
			],
			Submission::STATUS_SPAM => ['recranetforms_submissions.isSpam' => true],
			Submission::STATUS_UNPAID => [
				'and',
				['recranetforms_submissions.isSpam' => false],
				['not', ['recranetforms_submissions.paymentStatus' => null]],
				['not', ['recranetforms_submissions.paymentStatus' => Submission::PAYMENT_PAID]],
			],
			Submission::STATUS_FAILED => [
				'and',
				['recranetforms_submissions.isSpam' => false],
				['not', ['recranetforms_submissions.sendError' => null]],
				$paymentSettled,
			],
			default => parent::statusCondition($status),
		};
	}

	protected function beforePrepare(): bool
	{
		$this->joinElementTable('recranetforms_submissions');

		$this->query->select([
			'recranetforms_submissions.formId',
			'recranetforms_submissions.formData',
			'recranetforms_submissions.snapshot',
			'recranetforms_submissions.isSpam',
			'recranetforms_submissions.spamScore',
			'recranetforms_submissions.spamReason',
			'recranetforms_submissions.sendError',
			'recranetforms_submissions.incrementalId',
			'recranetforms_submissions.token',
			'recranetforms_submissions.sourceUrl',
			'recranetforms_submissions.idempotencyKey',
			'recranetforms_submissions.anonymizedAt',
			'recranetforms_submissions.paymentStatus',
			'recranetforms_submissions.paymentId',
			'recranetforms_submissions.paymentAmount',
		]);

		if ($this->formId !== null) {
			$this->subQuery->andWhere(Db::parseNumericParam('recranetforms_submissions.formId', $this->formId));
		}

		if ($this->isSpam !== null) {
			$this->subQuery->andWhere(['recranetforms_submissions.isSpam' => $this->isSpam]);
		}

		return parent::beforePrepare();
	}

	/**
	 * Decode the JSON columns when populating elements.
	 */
	public function createElement(array $row): \craft\base\ElementInterface
	{
		$row['formData'] = Json::decodeIfJson($row['formData'] ?? '[]') ?? [];
		$row['snapshot'] = Json::decodeIfJson($row['snapshot'] ?? '[]') ?? [];
		$row['isSpam'] = (bool)($row['isSpam'] ?? false);
		$row['spamScore'] = isset($row['spamScore']) ? (float)$row['spamScore'] : null;
		$row['incrementalId'] = isset($row['incrementalId']) ? (int)$row['incrementalId'] : null;
		// DB datetime string → DateTime (null when never anonymized)
		$row['anonymizedAt'] = isset($row['anonymizedAt']) ? (DateTimeHelper::toDateTime($row['anonymizedAt']) ?: null) : null;
		$row['paymentAmount'] = isset($row['paymentAmount']) ? (int)$row['paymentAmount'] : null;

		return parent::createElement($row);
	}
}
