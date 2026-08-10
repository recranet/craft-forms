<?php

namespace recranet\forms\elements;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\UploadedFile;
use recranet\forms\elements\actions\MarkAsHam;
use recranet\forms\elements\actions\ResendNotification;
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

	/** Element status for a payment that hasn't succeeded (yet) */
	public const STATUS_UNPAID = 'unpaid';

	// Normalized payment statuses (providers map their own onto these).
	// null in paymentStatus = this submission never involved a payment.
	public const PAYMENT_PENDING = 'pending';
	public const PAYMENT_PAID = 'paid';
	public const PAYMENT_FAILED = 'failed';
	public const PAYMENT_EXPIRED = 'expired';
	public const PAYMENT_CANCELED = 'canceled';

	/**
	 * Stored (English) sendError text for a failed notification send — one
	 * constant so the submit flow, the element actions and the CP resend
	 * buttons all record the exact same diagnostic.
	 */
	public const SEND_ERROR_NOTIFICATION = 'Notification email failed to send — see the log for the transport error.';

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

	/**
	 * Set when retention pruning anonymized this submission (form retention
	 * mode "anonymize"): the row survives for statistics, but all personal
	 * data — formData values, token, sourceUrl, uploaded files — is gone.
	 */
	public ?\DateTime $anonymizedAt = null;

	/**
	 * @var array<int, array{author: string, authorId: ?int, date: string, text: string}>
	 * Editor notes from the CP detail view, newest last. The author name is a
	 * snapshot (a deleted user keeps their name on old notes); `date` is an
	 * ISO-8601 string. Cleared on anonymize — a note may quote exactly the
	 * personal data the pruning removed.
	 */
	public array $notes = [];

	/** Normalized payment status (PAYMENT_*), or null when no payment was involved */
	public ?string $paymentStatus = null;

	/** The provider's payment id — the webhook/return lookup key */
	public ?string $paymentId = null;

	/** Amount owed, in whole cents (EUR) — computed server-side, never posted */
	public ?int $paymentAmount = null;

	/**
	 * @var array<string, UploadedFile> Pending file-field uploads keyed by
	 * field uid. Collected in applyPost(), validated (extension/size) in
	 * validateFormData(), and only turned into assets in beforeSave() — after
	 * validation passed and the spam pipeline decided to store the submission
	 * — so a rejected/invalid submit never leaves an orphaned asset behind.
	 */
	private array $pendingUploads = [];

	public static function displayName(): string
	{
		return Craft::t('recranet-forms', 'Submission');
	}

	public static function pluralDisplayName(): string
	{
		return Craft::t('recranet-forms', 'Submissions');
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
			self::STATUS_SENT => ['label' => Craft::t('recranet-forms', 'Sent'), 'color' => 'green'],
			self::STATUS_UNPAID => ['label' => Craft::t('recranet-forms', 'Awaiting payment'), 'color' => 'blue'],
			self::STATUS_SPAM => ['label' => Craft::t('recranet-forms', 'Spam'), 'color' => 'red'],
			self::STATUS_FAILED => ['label' => Craft::t('recranet-forms', 'Failed'), 'color' => 'orange'],
		];
	}

	public function getStatus(): ?string
	{
		if ($this->isSpam) {
			return self::STATUS_SPAM;
		}

		// A payment that hasn't succeeded (pending, failed, expired,
		// canceled) keeps the submission in "awaiting payment" — emails only
		// go out on the transition to paid (see Payments::syncStatus())
		if ($this->paymentStatus !== null && $this->paymentStatus !== self::PAYMENT_PAID) {
			return self::STATUS_UNPAID;
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
			// Layout-only rows (heading, paragraph) carry no input — nothing to store
			if (in_array($field['type'], Form::LAYOUT_TYPES, true)) {
				continue;
			}

			// File uploads never arrive in body params; stash the UploadedFile
			// for validation, the asset is only created in beforeSave()
			if ($field['type'] === 'file') {
				$upload = UploadedFile::getInstanceByName("fields[{$field['handle']}]");

				if ($upload && $upload->error !== UPLOAD_ERR_NO_FILE) {
					$this->pendingUploads[$field['uid']] = $upload;
				}

				$this->formData[$field['uid']] = null;
				$byHandle[$field['handle']] = null;
				continue;
			}

			$value = $posted[$field['handle']] ?? null;
			$value = is_string($value) ? trim($value) : $value;

			// Checkbox-like types store a boolean. For consent that boolean is
			// the agreement; the exact consent text the visitor saw lives in
			// the field row's `description`, which lands in $this->snapshot
			// below — so what was agreed to is snapshotted per submission
			// automatically, even if the text changes later.
			if ($field['type'] === 'checkbox' || $field['type'] === 'consent') {
				$value = (bool)$value;
			}

			// Multi-value checkboxes post as fields[handle][]: keep an array
			// of non-empty trimmed strings, whatever a hostile client sends
			if ($field['type'] === 'checkboxes') {
				$value = array_values(array_filter(
					array_map(
						fn($option) => is_scalar($option) ? trim((string)$option) : '',
						is_array($value) ? $value : ($value !== null && $value !== '' ? [$value] : []),
					),
					fn(string $option) => $option !== '',
				));
			}

			// Hidden fields can be seeded from a query param, so sanitize
			// hard: scalar only, cast to string, capped at 255 chars
			if ($field['type'] === 'hidden') {
				$value = is_scalar($value) ? mb_substr(trim((string)$value), 0, 255) : '';
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
		//
		// Rules see a pending upload as its client filename: the asset id
		// doesn't exist yet, and the front-end JS compares that same name —
		// without this, "show X when [file] is not empty" would pass in the
		// browser and fail here.
		$dataForRules = $this->formData;

		foreach ($this->pendingUploads as $uploadUid => $upload) {
			$dataForRules[$uploadUid] = $upload->name;
		}

		foreach (RuleEvaluator::hiddenFieldUids($form->fields, $dataForRules) as $uid) {
			$this->formData[$uid] = null;
			// A file field hidden by its conditions must not create an asset either
			unset($this->pendingUploads[$uid]);

			if ($field = $form->getFieldByUid($uid)) {
				$byHandle[$field['handle']] = null;
			}
		}

		// Canonical dedupe key: same form + same content = same key.
		// Computed after the conditional null-out, so hidden-field junk
		// can't make two otherwise identical submits look different.
		// File fields count as their client filename + size (the asset id
		// doesn't exist yet, and would differ between two identical posts).
		$keyData = $this->formData;

		foreach ($this->pendingUploads as $uid => $upload) {
			$keyData[$uid] = 'file:' . $upload->name . ':' . $upload->size;
		}

		$this->idempotencyKey = hash('sha256', $form->id . '|' . Json::encode($keyData));

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
		// Pending uploads count as their filename, same as in applyPost().
		$dataForRules = $this->formData;

		foreach ($this->pendingUploads as $uploadUid => $upload) {
			$dataForRules[$uploadUid] = $upload->name;
		}

		$hiddenUids = array_flip(RuleEvaluator::hiddenFieldUids($this->snapshot, $dataForRules));

		foreach ($this->snapshot as $field) {
			$handle = $field['handle'];
			$value = $this->formData[$field['uid']] ?? null;

			// Layout-only rows (heading, paragraph) have no value to validate
			if (in_array($field['type'], Form::LAYOUT_TYPES, true)) {
				continue;
			}

			if (isset($hiddenUids[$field['uid'] ?? ''])) {
				continue;
			}

			// A hidden field has no visible input the visitor could fill, so a
			// required flag on it can only lock everyone out — never enforce it
			$skipRequired = $field['type'] === 'hidden';

			// A pending upload counts as a value for a required file field
			// (the asset id only lands in formData at save time)
			$hasValue = !($value === null || $value === '' || $value === false || $value === [])
				|| isset($this->pendingUploads[$field['uid']]);

			if (!empty($field['required']) && !$skipRequired && !$hasValue) {
				// Editors can override the default message per field (Advanced tab).
				// This is also the consent check: an unchecked consent box is false = empty.
				$message = !empty($field['errorMessage'])
					? Craft::t('site', $field['errorMessage'])
					: Craft::t('recranet-forms', '{label} is required.', ['label' => Craft::t('site', $field['label'])]);
				$this->addError("field.{$handle}", $message);
				continue;
			}

			if ($field['type'] === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
				$this->addError("field.{$handle}", Craft::t('recranet-forms', '{label} must be a valid email address.', ['label' => Craft::t('site', $field['label'])]));
			}

			// Reject values that aren't one of the configured choice options
			if (in_array($field['type'], ['select', 'radio'], true) && $value) {
				$options = array_map('trim', explode(',', $field['options'] ?? ''));

				if (!in_array($value, $options, true)) {
					$this->addError("field.{$handle}", Craft::t('recranet-forms', '{label} has an invalid value.', ['label' => Craft::t('site', $field['label'])]));
				}
			}

			// Multi-value: every submitted option must be a configured one
			if ($field['type'] === 'checkboxes' && is_array($value) && $value !== []) {
				$options = array_map('trim', explode(',', $field['options'] ?? ''));

				if (array_diff($value, $options) !== []) {
					$this->addError("field.{$handle}", Craft::t('recranet-forms', '{label} has an invalid value.', ['label' => Craft::t('site', $field['label'])]));
				}
			}

			if ($field['type'] === 'number' && $value !== null && $value !== '' && !is_numeric($value)) {
				$this->addError("field.{$handle}", Craft::t('recranet-forms', '{label} must be a number.', ['label' => Craft::t('site', $field['label'])]));
			}

			// Dates must be exactly what <input type="date"> posts: Y-m-d
			if ($field['type'] === 'date' && $value) {
				$parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('Y-m-d', $value) : false;

				if (!$parsed || $parsed->format('Y-m-d') !== $value) {
					$this->addError("field.{$handle}", Craft::t('recranet-forms', '{label} must be a valid date.', ['label' => Craft::t('site', $field['label'])]));
				}
			}

			// Times must be what <input type="time"> posts: H:i, or H:i:s
			// when the input carries a seconds step
			if ($field['type'] === 'time' && $value) {
				$valid = false;

				foreach (['H:i', 'H:i:s'] as $format) {
					$parsed = is_string($value) ? \DateTimeImmutable::createFromFormat($format, $value) : false;

					if ($parsed && $parsed->format($format) === $value) {
						$valid = true;
						break;
					}
				}

				if (!$valid) {
					$this->addError("field.{$handle}", Craft::t('recranet-forms', '{label} must be a valid time.', ['label' => Craft::t('site', $field['label'])]));
				}
			}

			if ($field['type'] === 'url' && $value && !filter_var($value, FILTER_VALIDATE_URL)) {
				$this->addError("field.{$handle}", Craft::t('recranet-forms', '{label} must be a valid URL.', ['label' => Craft::t('site', $field['label'])]));
			}

			if ($field['type'] === 'file') {
				$this->validateUpload($field);
			}
		}
	}

	/**
	 * Validate a pending file upload BEFORE any asset is created: extension
	 * allowlist and size cap come from the plugin settings, and a missing
	 * upload volume is a config problem surfaced as a field error (never a
	 * silently dropped file). The client filename is only ever used for the
	 * extension check and the asset title — Craft's asset layer sanitizes it.
	 */
	private function validateUpload(array $field): void
	{
		$upload = $this->pendingUploads[$field['uid']] ?? null;

		if (!$upload) {
			return;
		}

		$handle = $field['handle'];
		$settings = Plugin::getInstance()->getSettings();

		if ($upload->hasError) {
			$this->addError("field.{$handle}", Craft::t('recranet-forms', '{label} could not be uploaded. Please try again.', ['label' => Craft::t('site', $field['label'])]));

			return;
		}

		// No volume configured = uploads can't be stored anywhere
		if ($settings->getUploadVolume() === '' || !Craft::$app->getVolumes()->getVolumeByHandle($settings->getUploadVolume())) {
			$this->addError("field.{$handle}", Craft::t('recranet-forms', 'File uploads are not configured. Please contact the site owner.'));

			return;
		}

		$extension = mb_strtolower(pathinfo($upload->name, PATHINFO_EXTENSION));
		$allowed = $settings->getAllowedFileExtensions();

		if (!in_array($extension, $allowed, true)) {
			$this->addError("field.{$handle}", Craft::t('recranet-forms', '{label} must be one of the following file types: {extensions}.', [
				'label' => $field['label'],
				'extensions' => implode(', ', $allowed),
			]));
		}

		if ($upload->size > $settings->getMaxUploadSize() * 1024 * 1024) {
			$this->addError("field.{$handle}", Craft::t('recranet-forms', '{label} may be at most {max} MB.', [
				'label' => $field['label'],
				'max' => $settings->getMaxUploadSize(),
			]));
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
			// Layout-only rows (heading, paragraph) never carry a value, so
			// emails, the CP view and exports all skip them via this one gate
			if (in_array($field['type'] ?? '', Form::LAYOUT_TYPES, true)) {
				continue;
			}

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
				'label' => Craft::t('recranet-forms', 'All submissions'),
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
			'form' => ['label' => Craft::t('recranet-forms', 'Form')],
			'preview' => ['label' => Craft::t('recranet-forms', 'Preview')],
			'spamReason' => ['label' => Craft::t('recranet-forms', 'Spam reason')],
			'sourceUrl' => ['label' => Craft::t('recranet-forms', 'Source')],
			'dateCreated' => ['label' => Craft::t('recranet-forms', 'Date submitted')],
		];
	}

	protected static function defineDefaultTableAttributes(string $source): array
	{
		return ['incrementalId', 'form', 'preview', 'dateCreated'];
	}

	protected static function defineSortOptions(): array
	{
		return [
			'dateCreated' => Craft::t('recranet-forms', 'Date submitted'),
		];
	}

	/**
	 * Index bulk actions: false-positive recovery ("Not spam") and resending
	 * the owner notification. Both are gated on the view-submissions
	 * permission — the same one the index screen itself requires.
	 */
	protected static function defineActions(string $source): array
	{
		$actions = parent::defineActions($source);

		if (Craft::$app->getUser()->checkPermission('recranetForms-viewSubmissions')) {
			$actions[] = MarkAsHam::class;
			$actions[] = ResendNotification::class;
		}

		return $actions;
	}

	/**
	 * Row attributes on the index — the element actions' triggers read
	 * `data-spam` to enable "Not spam" only on spam rows and "Resend
	 * notification" only on non-spam rows.
	 */
	protected function htmlAttributes(string $context): array
	{
		$attributes = parent::htmlAttributes($context);

		if ($this->isSpam) {
			$attributes['data-spam'] = true;
		}

		return $attributes;
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
			// Multi-value fields (checkboxes) contribute each selected option
			fn($value) => is_array($value)
				? implode(' ', array_filter($value, 'is_scalar'))
				: (is_scalar($value) ? (string)$value : ''),
			$this->formData,
		));
	}

	public function getForm(): ?Form
	{
		return $this->formId ? Plugin::getInstance()->forms->getFormById($this->formId) : null;
	}

	/**
	 * False-positive recovery: clear the spam flag and send the notification
	 * + confirmation emails that were skipped when the submission was
	 * flagged. The original spam reason is kept, prefixed with "Overridden: ",
	 * as an audit trail of why it was flagged and that a human reversed it.
	 *
	 * Returns false when the form no longer exists (nothing changes then) or
	 * when the notification email failed to send — in the latter case the
	 * flag IS cleared and the failure is recorded in sendError (status
	 * "failed"), exactly like the submit flow.
	 */
	public function markAsHam(): bool
	{
		$form = $this->getForm();

		// Without the form there is no recipient/template config — bail
		// before touching the flag, so the submission stays reviewable
		if (!$form) {
			Plugin::error("Submission #{$this->id}: cannot mark as not spam — form #{$this->formId} no longer exists.");

			return false;
		}

		$this->isSpam = false;
		$this->spamReason = 'Overridden: ' . ($this->spamReason ?: 'flagged as spam');

		// resendNotification() sends the owner mail, records/clears sendError
		// and saves the element — persisting the flag flip above too
		$sent = $this->resendNotification();

		// Confirmation guards itself (sendConfirmation setting + email field)
		Plugin::getInstance()->notifications->sendConfirmation($form, $this);

		return $sent;
	}

	/**
	 * (Re)send the owner notification email. Clears sendError on success,
	 * records it on failure (status "failed"), and saves the element either
	 * way. Returns false when the form no longer exists or the send failed.
	 */
	public function resendNotification(): bool
	{
		$form = $this->getForm();

		if (!$form) {
			Plugin::error("Submission #{$this->id}: cannot send notification — form #{$this->formId} no longer exists.");

			return false;
		}

		if (Plugin::getInstance()->notifications->sendNotification($form, $this)) {
			$this->sendError = null;
		} else {
			$this->sendError = self::SEND_ERROR_NOTIFICATION;
		}

		if (!Craft::$app->getElements()->saveElement($this)) {
			Plugin::error("Submission #{$this->id}: could not be saved after sending the notification: " . implode('; ', $this->getFirstErrors()));

			return false;
		}

		return $this->sendError === null;
	}

	/**
	 * Short label for index rows: first non-empty submitted value.
	 */
	public function getPreviewText(): string
	{
		foreach ($this->getValues() as $row) {
			$value = $row['value'];

			// Multi-value fields (checkboxes) read as a comma-joined list
			if (is_array($value)) {
				$value = implode(', ', array_filter($value, 'is_scalar'));
			}

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
		return UrlHelper::cpUrl('recranet-forms/submissions/' . $this->id);
	}

	public function canView(User $user): bool
	{
		return $user->can('recranetForms-viewSubmissions');
	}

	public function canSave(User $user): bool
	{
		return $user->can('recranetForms-viewSubmissions');
	}

	public function canDelete(User $user): bool
	{
		return $user->can('recranetForms-deleteSubmissions');
	}

	/**
	 * Turn pending file uploads into assets right before the element row is
	 * written. Running here (not in applyPost) means invalid submissions,
	 * rejected spam and deduped double posts never create an asset. Trade-off:
	 * in mail-only mode (saveSubmissions off) the element is never saved, so
	 * file fields stay empty in the notification email.
	 */
	public function beforeSave(bool $isNew): bool
	{
		if (!parent::beforeSave($isNew)) {
			return false;
		}

		return $this->savePendingUploads();
	}

	/**
	 * Create an asset per pending upload and store its id in formData.
	 * Extension/size/volume were already validated in validateFormData().
	 */
	private function savePendingUploads(): bool
	{
		if (!$this->pendingUploads) {
			return true;
		}

		// Consumed exactly once: a later re-save (e.g. recording a sendError)
		// must not try to create the assets again
		$uploads = $this->pendingUploads;
		$this->pendingUploads = [];

		$settings = Plugin::getInstance()->getSettings();
		$volume = Craft::$app->getVolumes()->getVolumeByHandle($settings->getUploadVolume());

		if (!$volume) {
			// validateFormData() already errors on this; belt and braces
			return false;
		}

		// Uploads live in a per-form subfolder of the configured volume
		$folderPath = $this->getForm()?->handle ?? 'form-' . $this->formId;
		$folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume($folderPath, $volume, false);

		foreach ($uploads as $uid => $upload) {
			// Move the PHP upload to a temp path Craft may relocate; the
			// client filename is untrusted and only feeds the asset
			// title/filename, which Craft's asset layer sanitizes
			$tempPath = $upload->saveAsTempFile();

			if ($tempPath === false) {
				Plugin::error("Submission for form #{$this->formId}: could not read uploaded file \"{$upload->name}\".");

				return false;
			}

			$asset = new Asset();
			$asset->tempFilePath = $tempPath;
			$asset->setFilename($upload->name);
			$asset->newFolderId = $folder->id;
			$asset->setVolumeId($volume->id);
			$asset->avoidFilenameConflicts = true;
			$asset->setScenario(Asset::SCENARIO_CREATE);

			if (!Craft::$app->getElements()->saveElement($asset)) {
				Plugin::error("Submission for form #{$this->formId}: could not save uploaded file \"{$upload->name}\": " . implode('; ', $asset->getFirstErrors()));

				return false;
			}

			$this->formData[$uid] = $asset->id;
		}

		return true;
	}

	/**
	 * Persist the submission row alongside the element row. New submissions
	 * get their token and per-form reference number here.
	 */
	public function afterSave(bool $isNew): void
	{
		if ($isNew) {
			$this->token = $this->token ?? StringHelper::randomString(32);

			// Per-form reference number. FOR UPDATE locks the current top row
			// until the element save transaction commits, so a concurrent
			// submit blocks here instead of reading the same number. A plain
			// MAX() would not lock anything (and Postgres rejects aggregates
			// with FOR UPDATE), hence the ORDER BY/LIMIT form. Two truly-first
			// submissions have no row to lock and could in theory still
			// collide — the number is a human reference, not a key.
			$max = Craft::$app->getDb()->createCommand(
				'SELECT [[incrementalId]] FROM {{%recranetforms_submissions}}'
				. ' WHERE [[formId]] = :formId ORDER BY [[incrementalId]] DESC LIMIT 1 FOR UPDATE',
				['formId' => $this->formId],
			)->queryScalar();
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
			'anonymizedAt' => Db::prepareDateForDb($this->anonymizedAt),
			'paymentStatus' => $this->paymentStatus,
			'paymentId' => $this->paymentId,
			'paymentAmount' => $this->paymentAmount,
			'notes' => Json::encode($this->notes),
		];

		if ($isNew) {
			Db::insert('{{%recranetforms_submissions}}', ['id' => $this->id] + $data);
		} else {
			Db::update('{{%recranetforms_submissions}}', $data, ['id' => $this->id]);
		}

		parent::afterSave($isNew);
	}

	/**
	 * Blank the personal data while keeping the element row (retention mode
	 * "anonymize"): every formData value goes to null, uploaded files are
	 * hard-deleted (a file IS personal data), and the token, source URL and
	 * dedupe key are cleared — nulling the token also kills the tokenized
	 * self-service link. The snapshot (field definitions, no values) stays,
	 * so the CP view still shows WHICH fields the form had.
	 *
	 * Saved without validation: required fields are now null by design, and
	 * that must not block the cleanup.
	 */
	public function anonymize(): bool
	{
		// Already processed — never re-anonymize (and never bump anonymizedAt)
		if ($this->anonymizedAt !== null) {
			return true;
		}

		// Uploaded files first, while formData still holds the asset ids
		foreach ($this->snapshot as $field) {
			if (($field['type'] ?? null) !== 'file') {
				continue;
			}

			$assetId = $this->formData[$field['uid'] ?? ''] ?? null;

			if ($assetId && is_numeric($assetId)) {
				Craft::$app->getElements()->deleteElementById((int)$assetId, Asset::class, hardDelete: true);
			}
		}

		// Null every stored value (layout-only types are already absent from
		// formData, so this covers exactly the fields that carried input)
		foreach ($this->formData as $uid => $value) {
			$this->formData[$uid] = null;
		}

		$this->token = null;
		$this->sourceUrl = null;
		$this->idempotencyKey = null;
		// Notes go too: an editor's note may quote exactly the personal data
		// this cleanup removes
		$this->notes = [];
		$this->anonymizedAt = new \DateTime();

		// Re-save so the search index drops the old keywords too
		return Craft::$app->getElements()->saveElement($this, runValidation: false);
	}

	/**
	 * Delete uploaded assets along with the submission — but ONLY on a hard
	 * delete: a soft delete (CP trash) must keep the files so restoring the
	 * submission restores its attachments. Retention pruning hard-deletes,
	 * so GDPR cleanup removes the uploaded files too.
	 */
	public function afterDelete(): void
	{
		if ($this->hardDelete) {
			foreach ($this->snapshot as $field) {
				if (($field['type'] ?? null) !== 'file') {
					continue;
				}

				$assetId = $this->formData[$field['uid'] ?? ''] ?? null;

				if ($assetId && is_numeric($assetId)) {
					Craft::$app->getElements()->deleteElementById((int)$assetId, Asset::class, hardDelete: true);
				}
			}
		}

		parent::afterDelete();
	}
}
