<?php

namespace recranet\forms\models;

use Craft;
use craft\base\Model;
use craft\validators\HandleValidator;
use recranet\forms\rules\RuleEvaluator;

/**
 * A form definition managed in the CP.
 *
 * Fields are stored as an ordered array of rows:
 * [
 *   ['uid' => '...', 'handle' => 'name', 'label' => 'Naam', 'type' => 'text', 'required' => true, 'options' => '', 'width' => 'full'],
 *   ...
 * ]
 * Supported types: text, email, tel, textarea, select, checkbox.
 * For select fields, `options` holds comma-separated choices.
 * `width` is 'full' or 'half' (half-width fields render side by side).
 *
 * The `uid` is the field's stable identity: submissions key their stored
 * values by uid, so renaming a handle never orphans historical data.
 * Handles are labels for templates/merge use only. Uids are assigned by
 * Forms::saveForm() — rows posted from the builder without one get one.
 *
 * Notification settings live on the form so editors can manage recipients
 * per form on production (forms are content, not project config).
 */
class Form extends Model
{
	/** Field types the front-end render template and validator understand */
	public const FIELD_TYPES = ['text', 'email', 'tel', 'textarea', 'select', 'checkbox'];

	public ?int $id = null;
	public ?string $name = null;
	public ?string $handle = null;

	/** @var array<int, array{uid: string, handle: string, label: string, type: string, required: bool, options: string, width: string}> */
	public array $fields = [];

	/** Comma-separated notification recipients; falls back to the system email when empty */
	public string $recipients = '';

	/** Subject line of the notification email */
	public string $subject = '';

	/** Whether to send a confirmation email to the submitter (requires an email field) */
	public bool $sendConfirmation = false;

	/** Subject line of the confirmation email */
	public string $confirmationSubject = '';

	public ?string $uid = null;

	protected function defineRules(): array
	{
		return [
			[['name', 'handle'], 'required'],
			[['handle'], HandleValidator::class],
			[['fields'], 'validateFields'],
		];
	}

	/**
	 * Validate the field rows: every row needs a handle, label and known type,
	 * and handles must be unique within the form.
	 */
	public function validateFields(): void
	{
		$handles = [];

		foreach ($this->fields as $i => $field) {
			$row = $i + 1;

			if (empty($field['handle']) || empty($field['label'])) {
				$this->addError('fields', "Field row {$row}: handle and label are required.");
				continue;
			}

			if (!in_array($field['type'] ?? '', self::FIELD_TYPES, true)) {
				$this->addError('fields', "Field row {$row}: unknown type.");
			}

			if (in_array($field['handle'], $handles, true)) {
				$this->addError('fields', "Field row {$row}: duplicate handle \"{$field['handle']}\".");
			}

			$handles[] = $field['handle'];
		}

		$this->checkConditionShapes();
	}

	/**
	 * Sanity-check the optional `conditions` on each field row. Deliberately
	 * lenient: broken shapes are logged as warnings, never validation errors —
	 * the RuleEvaluator fails open on anything malformed (a broken rule shows
	 * the field instead of hiding it), so a bad rule must not block saving
	 * the form.
	 */
	private function checkConditionShapes(): void
	{
		// Known uids a rule may reference (rows without one get a uid in saveForm, but can't be referenced yet)
		$uids = array_filter(array_column($this->fields, 'uid'));

		foreach ($this->fields as $i => $field) {
			$conditions = $field['conditions'] ?? null;

			if (!is_array($conditions)) {
				continue;
			}

			$row = $i + 1;
			$mode = $conditions['mode'] ?? null;

			if ($mode !== null && !in_array($mode, ['all', 'any'], true)) {
				Craft::warning("Form \"{$this->handle}\", field row {$row}: unknown conditions mode \"{$mode}\" (treated as \"all\").", __METHOD__);
			}

			foreach ((array)($conditions['rules'] ?? []) as $rule) {
				if (!is_array($rule)) {
					continue;
				}

				if (!empty($rule['operator']) && !in_array($rule['operator'], RuleEvaluator::OPERATORS, true)) {
					Craft::warning("Form \"{$this->handle}\", field row {$row}: unknown condition operator \"{$rule['operator']}\" (rule ignored).", __METHOD__);
				}

				if (!empty($rule['field']) && !in_array($rule['field'], $uids, true)) {
					Craft::warning("Form \"{$this->handle}\", field row {$row}: condition references unknown field uid \"{$rule['field']}\" (rule ignored).", __METHOD__);
				}

				// A field must not depend on itself — the evaluator would treat its own (possibly hidden) value as input
				if (($rule['field'] ?? null) === ($field['uid'] ?? '')) {
					Craft::warning("Form \"{$this->handle}\", field row {$row}: condition references the field itself.", __METHOD__);
				}
			}
		}
	}

	/**
	 * Recipient list as an array, falling back to the system email.
	 */
	public function getRecipientList(): array
	{
		$recipients = array_filter(array_map('trim', explode(',', $this->recipients)));

		if (!$recipients) {
			$systemEmail = \craft\helpers\App::mailSettings()->fromEmail;
			$recipients = $systemEmail ? [\craft\helpers\App::parseEnv($systemEmail)] : [];
		}

		return $recipients;
	}

	/**
	 * The handle of the first email field, used as reply-to and for confirmations.
	 */
	public function getEmailFieldHandle(): ?string
	{
		foreach ($this->fields as $field) {
			if (($field['type'] ?? null) === 'email') {
				return $field['handle'];
			}
		}

		return null;
	}

	/**
	 * Look up a field row by its uid.
	 */
	public function getFieldByUid(string $uid): ?array
	{
		foreach ($this->fields as $field) {
			if (($field['uid'] ?? null) === $uid) {
				return $field;
			}
		}

		return null;
	}
}
