<?php

namespace elloro\forms\elements;

use Craft;
use craft\base\Element;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use elloro\forms\elements\db\SubmissionQuery;
use elloro\forms\Plugin;

/**
 * A stored form submission.
 *
 * Field values live in the `content` JSON column keyed by field handle.
 * Spam-flagged submissions are kept and visible in the CP (never silently
 * dropped); `spamReason` records why — including reCAPTCHA config errors,
 * so a broken key setup is diagnosable from the submissions index.
 */
class Submission extends Element
{
	public ?int $formId = null;

	/** @var array<string, mixed> Submitted values keyed by field handle */
	public array $content = [];

	public bool $isSpam = false;
	public ?string $spamReason = null;

	public static function displayName(): string
	{
		return 'Submission';
	}

	public static function pluralDisplayName(): string
	{
		return 'Submissions';
	}

	public static function refHandle(): ?string
	{
		return 'formSubmission';
	}

	public static function hasStatuses(): bool
	{
		return true;
	}

	public static function statuses(): array
	{
		return [
			'live' => ['label' => 'Valid', 'color' => 'green'],
			'spam' => ['label' => 'Spam', 'color' => 'red'],
		];
	}

	public function getStatus(): ?string
	{
		return $this->isSpam ? 'spam' : 'live';
	}

	public static function find(): SubmissionQuery
	{
		return new SubmissionQuery(static::class);
	}

	/**
	 * One source per form, plus an "all" source, so the CP index can be
	 * filtered per form out of the box.
	 */
	protected static function defineSources(?string $context = null): array
	{
		$sources = [
			[
				'key' => '*',
				'label' => 'All submissions',
				'criteria' => [],
				'defaultSort' => ['dateCreated', 'desc'],
			],
		];

		foreach (Plugin::getInstance()->forms->getAllForms() as $form) {
			$sources[] = [
				'key' => 'form:' . $form->uid,
				'label' => $form->name,
				'criteria' => ['formId' => $form->id],
				'defaultSort' => ['dateCreated', 'desc'],
			];
		}

		return $sources;
	}

	protected static function defineTableAttributes(): array
	{
		return [
			'form' => ['label' => 'Form'],
			'preview' => ['label' => 'Preview'],
			'spamReason' => ['label' => 'Spam reason'],
			'dateCreated' => ['label' => 'Date submitted'],
		];
	}

	protected static function defineDefaultTableAttributes(string $source): array
	{
		return ['form', 'preview', 'dateCreated'];
	}

	protected static function defineSortOptions(): array
	{
		return [
			'dateCreated' => 'Date submitted',
		];
	}

	protected function attributeHtml(string $attribute): string
	{
		return match ($attribute) {
			'form' => Html::encode($this->getForm()?->name ?? '—'),
			'preview' => Html::encode($this->getPreviewText()),
			'spamReason' => Html::encode($this->spamReason ?? ''),
			default => parent::attributeHtml($attribute),
		};
	}

	protected static function defineSearchableAttributes(): array
	{
		return ['contentKeywords'];
	}

	/** Flattened content values so submissions are findable via CP search */
	public function getContentKeywords(): string
	{
		return implode(' ', array_map(
			fn($value) => is_scalar($value) ? (string)$value : Json::encode($value),
			$this->content,
		));
	}

	public function getForm(): ?\elloro\forms\models\Form
	{
		return $this->formId ? Plugin::getInstance()->forms->getFormById($this->formId) : null;
	}

	/**
	 * Short label for index rows: first non-empty submitted value.
	 */
	public function getPreviewText(): string
	{
		foreach ($this->content as $value) {
			if (is_string($value) && trim($value) !== '') {
				return mb_strimwidth(trim($value), 0, 60, '…');
			}
		}

		return '—';
	}

	public function getUiLabel(): string
	{
		return $this->getPreviewText();
	}

	public function getCpEditUrl(): ?string
	{
		return UrlHelper::cpUrl('elloro-forms/submissions/' . $this->id);
	}

	public function canView(User $user): bool
	{
		return $user->can('accessPlugin-elloro-forms');
	}

	public function canSave(User $user): bool
	{
		return $user->can('accessPlugin-elloro-forms');
	}

	public function canDelete(User $user): bool
	{
		return $user->can('accessPlugin-elloro-forms');
	}

	/**
	 * Persist the submission row alongside the element row.
	 */
	public function afterSave(bool $isNew): void
	{
		$data = [
			'formId' => $this->formId,
			'content' => Json::encode($this->content),
			'isSpam' => $this->isSpam,
			'spamReason' => $this->spamReason,
		];

		if ($isNew) {
			Db::insert('{{%elloroforms_submissions}}', ['id' => $this->id] + $data);
		} else {
			Db::update('{{%elloroforms_submissions}}', $data, ['id' => $this->id]);
		}

		parent::afterSave($isNew);
	}
}
