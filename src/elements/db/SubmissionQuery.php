<?php

namespace recranet\forms\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use craft\helpers\Json;

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
	 * Map the custom statuses onto the isSpam column.
	 */
	protected function statusCondition(string $status): mixed
	{
		return match ($status) {
			'live' => ['recranetforms_submissions.isSpam' => false],
			'spam' => ['recranetforms_submissions.isSpam' => true],
			default => parent::statusCondition($status),
		};
	}

	protected function beforePrepare(): bool
	{
		$this->joinElementTable('recranetforms_submissions');

		$this->query->select([
			'recranetforms_submissions.formId',
			'recranetforms_submissions.formData',
			'recranetforms_submissions.isSpam',
			'recranetforms_submissions.spamReason',
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
	 * Decode the JSON content column when populating elements.
	 */
	public function createElement(array $row): \craft\base\ElementInterface
	{
		$row['formData'] = Json::decodeIfJson($row['formData'] ?? '[]') ?? [];
		$row['isSpam'] = (bool)($row['isSpam'] ?? false);

		return parent::createElement($row);
	}
}
