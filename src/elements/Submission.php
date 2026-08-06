<?php

namespace recranet\forms\elements;

use Craft;
use craft\base\Element;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use recranet\forms\elements\db\SubmissionQuery;
use recranet\forms\models\Form;
use recranet\forms\Plugin;
use recranet\forms\rules\RuleEvaluator;

/**
 * A stored form submission.
 *
 * Field values live in the `formData` JSON column keyed by field **uid** —
 * never by handle, so renaming a field in the CP can't orphan historical
 * data. The `snapshot` column stores the form's field definitions as they
 * were at submit time; all handle/label lookups on old submissions resolve
 * against the snapshot, not the (possibly changed) live form.
 *
 * Spam-flagged submissions are kept and visible in the CP (never silently
 * dropped); `spamReason` records why — including reCAPTCHA config errors,
 * so a broken key setup is diagnosable from the submissions index. Mail
 * send failures are recorded in `sendError` (status "failed") instead of
 * losing the message.
 */
class Submission extends Element
{
	public const STATUS_SENT = 'sent';
	public const STATUS_SPAM = 'spam';
	public const STATUS_FAILED = 'failed';

	public ?int $formId = null;

	/** @var array<string, mixed> Submitted values keyed by field uid */
	public array $formData = [];

	/** @var array<int, array> Form field definitions at submit time */
	public array $snapshot = [];

	public bool $isSpam = false;
	public ?float $spamScore = null;
	public ?string $spamReason = null;

	/** Set when the notification email failed to send (status "failed") */
	public ?string $sendError = null;

	/** Human-friendly per-form reference number (#1, #2, ...) */
	public ?int $incrementalId = null;

	/** Random unguessable token, for tokenized view/delete links later */
	public ?string $token = null;

	/** Path the form was submitted from */
	public ?string $sourceUrl = null;

	/** Dedupe key: identical double submits within a short window are dropped */
	public ?string $idempotencyKey = null;

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

	/** Submissions belong to the site they were submitted on */
	public static function isLocalized(): bool
	{
		return true;
	}

	/** One elements_sites row only — a submission is not translated content */
	public function getSupportedSites(): array
	{
		return [$this->siteId ?? Craft::$app->getSites()->getCurrentSite()->id];
	}

	public static function hasStatuses(): bool
	{
		return true;
	}

	public static function statuses(): array
	{
		return [
			self::STATUS_SENT => ['label' => 'Sent', 'color' => 'green'],
			self::STATUS_SPAM => ['label' => 'Spam', 'color' => 'red'],
			self::STATUS_FAILED => ['label' => 'Failed', 'color' => 'orange'],
		];
	}

	public function getStatus(): ?string
	{
		if ($this->isSpam) {
			return self::STATUS_SPAM;
		}

		return $this->sendError ? self::STATUS_FAILED : self::STATUS_SENT;
	}

	public static function find(): SubmissionQuery
	{
		return new SubmissionQuery(static::class);
	}

	/**
	 * Normalize posted values against the form definition and store them
	 * keyed by field uid, plus the snapshot. Returns the normalized values
	 * keyed by *handle* for template re-rendering after validation errors.
	 *
	 * @param array<string, mixed> $posted Raw fields[...] body params, keyed by handle
	 * @return array<string, mixed>
	 */
	public function applyPost(Form $form, array $posted): array
	{
		$this->formId = $form->id;
		$this->snapshot = $form->fields;

		$byHandle = [];

		foreach ($form->fields as $field) {
			$value = $posted[$field['handle']] ?? null;
			$value = is_string($value) ? trim($value) : $value;

			if ($field['type'] === 'checkbox') {
				$value = (bool)$value;
			}

			$this->formData[$field['uid']] = $value;
			$byHandle[$field['handle']] = $value;
		}

		// Server-side enforcement of conditional visibility: values posted
		// for fields hidden by their conditions are discarded — a no-JS or
		// hostile client can post them even though the front end disables
		// hidden inputs. We cascade until the hidden set is stable (a hidden
		// field's value counts as empty for rules on other fields) instead
		// of evaluating once, so chained conditions resolve exactly like the
		// front-end JS, where a hidden field posts nothing.
		foreach (RuleEvaluator::hiddenFieldUids($form->fields, $this->formData) as $uid) {
			$this->formData[$uid] = null;

			if ($field = $form->getFieldByUid($uid)) {
				$byHandle[$field['handle']] = null;
			}
		}

		// Canonical dedupe key: same form + same content = same key.
		// Computed after the conditional null-out, so hidden-field junk
		// can't make two otherwise identical submits look different.
		$this->idempotencyKey = hash('sha256', $form->id . '|' . Json::encode($this->formData));

		return $byHandle;
	}

	protected function defineRules(): array
	{
		$rules = parent::defineRules();
		$rules[] = [['formId'], 'required'];
		$rules[] = [['formData'], 'validateFormData'];

		return $rules;
	}

	/**
	 * Validate stored values against the snapshot's field definitions.
	 * Errors are keyed "field.<handle>" so the controller can hand templates
	 * a handle-keyed error list.
	 */
	public function validateFormData(): void
	{
		// Fields hidden by their conditions are exempt from ALL validation:
		// a field the visitor never saw is never required — whatever its
		// required flag says — and its (discarded) value must not fail type
		// checks either. Recomputed here from the snapshot rather than
		// carried over from applyPost(), so validation is self-contained.
		$hiddenUids = array_flip(RuleEvaluator::hiddenFieldUids($this->snapshot, $this->formData));

		foreach ($this->snapshot as $field) {
			$handle = $field['handle'];
			$value = $this->formData[$field['uid']] ?? null;

			if (isset($hiddenUids[$field['uid'] ?? ''])) {
				continue;
			}

			if (!empty($field['required']) && ($value === null || $value === '' || $value === false)) {
				$this->addError("field.{$handle}", Craft::t('recranet-forms', '{label} is required.', ['label' => $field['label']]));
				continue;
			}

			if ($field['type'] === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
				$this->addError("field.{$handle}", Craft::t('recranet-forms', '{label} must be a valid email address.', ['label' => $field['label']]));
			}

			// Reject values that aren't one of the configured select options
			if ($field['type'] === 'select' && $value) {
				$options = array_map('trim', explode(',', $field['options'] ?? ''));

				if (!in_array($value, $options, true)) {
					$this->addError("field.{$handle}", Craft::t('recranet-forms', '{label} has an invalid value.', ['label' => $field['label']]));
				}
			}
		}
	}

	/**
	 * Validation errors keyed by field handle, for front-end re-rendering.
	 *
	 * @return array<string, string[]>
	 */
	public function getFieldErrors(): array
	{
		$errors = [];

		foreach ($this->getErrors() as $attribute => $messages) {
			if (str_starts_with($attribute, 'field.')) {
				$errors[substr($attribute, 6)] = $messages;
			}
		}

		return $errors;
	}

	/**
	 * Submitted values resolved through the snapshot, in field order:
	 * [['uid' => ..., 'handle' => ..., 'label' => ..., 'type' => ..., 'value' => ...], ...]
	 *
	 * This is the template-facing API — emails and CP views iterate this
	 * instead of poking uid-keyed formData directly.
	 *
	 * @return array<int, array{uid: string, handle: string, label: string, type: string, value: mixed}>
	 */
	public function getValues(): array
	{
		$values = [];

		foreach ($this->snapshot as $field) {
			$values[] = [
				'uid' => $field['uid'],
				'handle' => $field['handle'],
				'label' => $field['label'],
				'type' => $field['type'],
				'value' => $this->formData[$field['uid']] ?? null,
			];
		}

		return $values;
	}

	/**
	 * A single submitted value by field handle (resolved via the snapshot).
	 */
	public function value(string $handle): mixed
	{
		foreach ($this->snapshot as $field) {
			if ($field['handle'] === $handle) {
				return $this->formData[$field['uid']] ?? null;
			}
		}

		return null;
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
			'incrementalId' => ['label' => '#'],
			'form' => ['label' => 'Form'],
			'preview' => ['label' => 'Preview'],
			'spamReason' => ['label' => 'Spam reason'],
			'sourceUrl' => ['label' => 'Source'],
			'dateCreated' => ['label' => 'Date submitted'],
		];
	}

	protected static function defineDefaultTableAttributes(string $source): array
	{
		return ['incrementalId', 'form', 'preview', 'dateCreated'];
	}

	protected static function defineSortOptions(): array
	{
		return [
			'dateCreated' => 'Date submitted',
		];
	}

	/** Adds the expanded-fields CSV export next to Craft's default exporters */
	protected static function defineExporters(string $source): array
	{
		$exporters = parent::defineExporters($source);
		$exporters[] = \recranet\forms\elements\exporters\ExpandedSubmissions::class;

		return $exporters;
	}

	protected function attributeHtml(string $attribute): string
	{
		return match ($attribute) {
			'incrementalId' => $this->incrementalId ? '#' . $this->incrementalId : '—',
			'form' => Html::encode($this->getForm()?->name ?? '—'),
			'preview' => Html::encode($this->getPreviewText()),
			'spamReason' => Html::encode($this->spamReason ?? ''),
			'sourceUrl' => Html::encode($this->sourceUrl ?? ''),
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
			$this->formData,
		));
	}

	public function getForm(): ?Form
	{
		return $this->formId ? Plugin::getInstance()->forms->getFormById($this->formId) : null;
	}

	/**
	 * Short label for index rows: first non-empty submitted value.
	 */
	public function getPreviewText(): string
	{
		foreach ($this->getValues() as $row) {
			if (is_string($row['value']) && trim($row['value']) !== '') {
				return mb_strimwidth(trim($row['value']), 0, 60, '…');
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
		return UrlHelper::cpUrl('recranet-forms/submissions/' . $this->id);
	}

	public function canView(User $user): bool
	{
		return $user->can('accessPlugin-recranet-forms');
	}

	public function canSave(User $user): bool
	{
		return $user->can('accessPlugin-recranet-forms');
	}

	public function canDelete(User $user): bool
	{
		return $user->can('accessPlugin-recranet-forms');
	}

	/**
	 * Persist the submission row alongside the element row. New submissions
	 * get their token and per-form reference number here.
	 */
	public function afterSave(bool $isNew): void
	{
		if ($isNew) {
			$this->token = $this->token ?? StringHelper::randomString(32);

			// Per-form reference number; the row lock inside the element
			// save transaction keeps concurrent submits from colliding
			$max = (new \craft\db\Query())
				->from('{{%recranetforms_submissions}}')
				->where(['formId' => $this->formId])
				->max('[[incrementalId]]');
			$this->incrementalId = ((int)$max) + 1;
		}

		$data = [
			'formId' => $this->formId,
			'formData' => Json::encode($this->formData),
			'snapshot' => Json::encode($this->snapshot),
			'isSpam' => $this->isSpam,
			'spamScore' => $this->spamScore,
			'spamReason' => $this->spamReason,
			'sendError' => $this->sendError,
			'incrementalId' => $this->incrementalId,
			'token' => $this->token,
			'sourceUrl' => $this->sourceUrl,
			'idempotencyKey' => $this->idempotencyKey,
		];

		if ($isNew) {
			Db::insert('{{%recranetforms_submissions}}', ['id' => $this->id] + $data);
		} else {
			Db::update('{{%recranetforms_submissions}}', $data, ['id' => $this->id]);
		}

		parent::afterSave($isNew);
	}
}
