<?php

namespace elloro\forms\models;

use craft\base\Model;
use craft\validators\HandleValidator;

/**
 * A form definition managed in the CP.
 *
 * Fields are stored as an ordered array of rows:
 * [
 *   ['handle' => 'name', 'label' => 'Naam', 'type' => 'text', 'required' => true, 'options' => ''],
 *   ...
 * ]
 * Supported types: text, email, tel, textarea, select, checkbox.
 * For select fields, `options` holds comma-separated choices.
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

	/** @var array<int, array{handle: string, label: string, type: string, required: bool, options: string}> */
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
}
