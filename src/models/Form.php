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
 * Supported types: see FIELD_TYPES. For choice fields (select, radio,
 * checkboxes), `options` holds comma-separated choices. Layout-only types
 * (LAYOUT_TYPES) render markup but never store, validate or export a value.
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
	public const FIELD_TYPES = [
		'text', 'email', 'tel', 'textarea', 'select', 'checkbox',
		'radio', 'checkboxes', 'number', 'date', 'time', 'url', 'hidden',
		'consent', 'heading', 'paragraph', 'divider', 'file',
	];

	/**
	 * Layout-only types: they render markup (a heading, a paragraph) but carry
	 * no input. Everything that touches submitted values — validation,
	 * formData storage, emails, the CP detail view, exports, search keywords —
	 * must skip these. Always check against this const instead of hardcoding
	 * type names, so adding a layout type stays a one-line change.
	 */
	public const LAYOUT_TYPES = ['heading', 'paragraph', 'divider'];

	/** Retention mode: prune hard-deletes the whole submission (current behavior) */
	public const RETENTION_MODE_DELETE = 'delete';

	/**
	 * Retention mode: prune keeps the submission row (counts, dates, per-form
	 * reference numbers survive) but blanks all personal data — every formData
	 * value, the self-service token, the source URL and uploaded files.
	 */
	public const RETENTION_MODE_ANONYMIZE = 'anonymize';

	public ?int $id = null;
	public ?string $name = null;
	public ?string $handle = null;

	/** @var array<int, array{uid: string, handle: string, label: string, type: string, required: bool, options: string, width: string}> */
	public array $fields = [];

	/** Comma-separated notification recipients; falls back to the system email when empty */
	public string $recipients = '';

	/** Subject line of the notification email */
	public string $subject = '';

	/**
	 * Site template path overriding the notification email for this form
	 * (e.g. "_emails/quote-notification"). Empty = default resolution:
	 * templates/recranet-forms/_emails/notification.twig, else the plugin's.
	 */
	public string $notificationTemplate = '';

	/**
	 * Editor-managed intro text rendered above the field table in the
	 * notification email. Supports merge tags; plain text, newlines kept.
	 */
	public string $notificationIntro = '';

	/** Whether to send a confirmation email to the submitter (requires an email field) */
	public bool $sendConfirmation = false;

	/** Subject line of the confirmation email */
	public string $confirmationSubject = '';

	/** Site template path overriding the confirmation email for this form */
	public string $confirmationTemplate = '';

	/**
	 * Editor-managed body of the confirmation email. Supports merge tags;
	 * empty = the template's default text. Editable on production (forms are
	 * DB content), so no deploy is needed to tweak the thank-you mail.
	 */
	public string $confirmationBody = '';

	/**
	 * Per-form retention override in days. '' = inherit the plugin-wide
	 * retention setting; 0 = keep this form's submissions forever, whatever
	 * the plugin default says.
	 */
	public int|string $retentionDays = '';

	/**
	 * What retention pruning does with this form's expired submissions:
	 * RETENTION_MODE_DELETE (default) or RETENTION_MODE_ANONYMIZE.
	 */
	public string $retentionMode = self::RETENTION_MODE_DELETE;

	public ?string $uid = null;

	protected function defineRules(): array
	{
		return [
			[['name', 'handle'], 'required'],
			[['handle'], HandleValidator::class],
			[['fields'], 'validateFields'],
			// skipOnEmpty leaves '' (= inherit) alone
			[['retentionDays'], 'integer', 'min' => 0],
			[['retentionMode'], 'in', 'range' => [self::RETENTION_MODE_DELETE, self::RETENTION_MODE_ANONYMIZE]],
		];
	}

	/**
	 * The per-form retention override as an int, or null when the form
	 * inherits the plugin-wide setting ('').
	 */
	public function getRetentionDaysOverride(): ?int
	{
		if ($this->retentionDays === '') {
			return null;
		}

		return (int)$this->retentionDays;
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

			// A paragraph's content is its description (label = optional small
			// heading); a divider is pure markup and needs no label at all
			$labelOptional = in_array($field['type'] ?? '', ['paragraph', 'divider'], true);

			if (empty($field['handle']) || (empty($field['label']) && !$labelOptional)) {
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
