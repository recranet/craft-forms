<?php

namespace elloro\forms\elements\db;

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
			'live' => ['elloroforms_submissions.isSpam' => false],
			'spam' => ['elloroforms_submissions.isSpam' => true],
			default => parent::statusCondition($status),
		};
	}

	protected function beforePrepare(): bool
	{
		$this->joinElementTable('elloroforms_submissions');

		$this->query->select([
			'elloroforms_submissions.formId',
			'elloroforms_submissions.content',
			'elloroforms_submissions.isSpam',
			'elloroforms_submissions.spamReason',
		]);

		if ($this->formId !== null) {
			$this->subQuery->andWhere(Db::parseNumericParam('elloroforms_submissions.formId', $this->formId));
		}

		if ($this->isSpam !== null) {
			$this->subQuery->andWhere(['elloroforms_submissions.isSpam' => $this->isSpam]);
		}

		return parent::beforePrepare();
	}

	/**
	 * Decode the JSON content column when populating elements.
	 */
	protected function createElement(array $row): \craft\base\ElementInterface
	{
		$row['content'] = Json::decodeIfJson($row['content'] ?? '[]') ?? [];
		$row['isSpam'] = (bool)($row['isSpam'] ?? false);

		return parent::createElement($row);
	}
}
