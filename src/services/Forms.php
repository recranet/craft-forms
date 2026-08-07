<?php

namespace recranet\forms\services;

use craft\helpers\Json;
use craft\helpers\StringHelper;
use recranet\forms\models\Form;
use recranet\forms\records\FormRecord;
use yii\base\Component;

/**
 * CRUD for form definitions. Forms are content (database), not project
 * config, so editors can manage them on production where admin changes
 * are disabled.
 */
class Forms extends Component
{
	/** @var Form[]|null Memoized forms for the request */
	private ?array $allForms = null;

	/**
	 * @return Form[]
	 */
	public function getAllForms(): array
	{
		if ($this->allForms === null) {
			$this->allForms = array_map(
				fn(FormRecord $record) => $this->createModel($record),
				FormRecord::find()->orderBy(['name' => SORT_ASC])->all(),
			);
		}

		return $this->allForms;
	}

	public function getFormById(int $id): ?Form
	{
		$record = FormRecord::findOne($id);

		return $record ? $this->createModel($record) : null;
	}

	public function getFormByHandle(string $handle): ?Form
	{
		$record = FormRecord::findOne(['handle' => $handle]);

		return $record ? $this->createModel($record) : null;
	}

	/**
	 * Validate and persist a form. Validation errors end up on the model.
	 */
	public function saveForm(Form $form): bool
	{
		// Assign a stable uid to new field rows before validation, so the
		// saved definition is always uid-complete (submissions key by uid)
		foreach ($form->fields as &$field) {
			if (empty($field['uid'])) {
				$field['uid'] = StringHelper::UUID();
			}
		}
		unset($field);

		if (!$form->validate()) {
			return false;
		}

		$record = $form->id ? FormRecord::findOne($form->id) : new FormRecord();

		if (!$record) {
			return false;
		}

		$record->name = $form->name;
		$record->handle = $form->handle;
		$record->fields = Json::encode($form->fields);
		$record->settings = Json::encode([
			'recipients' => $form->recipients,
			'subject' => $form->subject,
			'notificationTemplate' => $form->notificationTemplate,
			'notificationIntro' => $form->notificationIntro,
			'sendConfirmation' => $form->sendConfirmation,
			'confirmationSubject' => $form->confirmationSubject,
			'confirmationTemplate' => $form->confirmationTemplate,
			'confirmationBody' => $form->confirmationBody,
			'retentionDays' => $form->retentionDays,
			'retentionMode' => $form->retentionMode,
			'paymentEnabled' => $form->paymentEnabled,
			'paymentBase' => $form->paymentBase,
		]);
		$record->uid = $record->uid ?: StringHelper::UUID();

		if (!$record->save()) {
			return false;
		}

		$form->id = $record->id;
		$form->uid = $record->uid;
		$this->allForms = null;

		return true;
	}

	/**
	 * Render the front-end form template for the CP preview. Uses the same
	 * site-override-then-plugin-default resolution as the real render, so
	 * the preview shows the markup visitors get.
	 */
	public function renderFormPreview(Form $form): string
	{
		$view = \Craft::$app->getView();
		$variables = ['form' => $form, 'options' => [], 'formErrors' => [], 'formContent' => [], 'erroredFormHandle' => null];

		if ($view->doesTemplateExist('recranet-forms/form', \craft\web\View::TEMPLATE_MODE_SITE)) {
			return $view->renderTemplate('recranet-forms/form', $variables, \craft\web\View::TEMPLATE_MODE_SITE);
		}

		return $view->renderTemplate('recranet-forms/_render/form', $variables, \craft\web\View::TEMPLATE_MODE_CP);
	}

	/**
	 * An unsaved submission filled with plausible sample values, for the CP
	 * email previews. Never saved — it exists only to render a template.
	 */
	public function sampleSubmission(Form $form): \recranet\forms\elements\Submission
	{
		$submission = new \recranet\forms\elements\Submission();
		$submission->formId = $form->id;
		$submission->snapshot = $form->fields;
		$submission->incrementalId = 1;
		$submission->sourceUrl = '/' . ($form->handle ?? '');
		$submission->dateCreated = new \DateTime();

		foreach ($form->fields as $field) {
			$options = array_values(array_filter(array_map('trim', explode(',', (string)($field['options'] ?? '')))));

			$submission->formData[$field['uid']] = match ($field['type']) {
				'email' => 'naam@voorbeeld.nl',
				'tel' => '06 12 34 56 78',
				'url' => 'https://voorbeeld.nl',
				'number' => '3',
				'date' => (new \DateTime())->format('Y-m-d'),
				'time' => '14:30',
				'textarea' => "Voorbeeldtekst.\nTweede regel.",
				'checkbox', 'consent' => true,
				'select', 'radio' => $options[0] ?? '',
				'checkboxes' => array_slice($options, 0, 2),
				'file' => null,
				default => $field['label'] ?? 'Voorbeeld',
			};
		}

		return $submission;
	}

	/**
	 * Portable array representation of a form (for JSON export). Field uids
	 * are included — conditions reference them, and they're globally unique
	 * so importing them elsewhere is safe.
	 */
	public function exportForm(Form $form): array
	{
		return [
			'name' => $form->name,
			'handle' => $form->handle,
			'fields' => $form->fields,
			'settings' => [
				'recipients' => $form->recipients,
				'subject' => $form->subject,
				'notificationTemplate' => $form->notificationTemplate,
				'notificationIntro' => $form->notificationIntro,
				'sendConfirmation' => $form->sendConfirmation,
				'confirmationSubject' => $form->confirmationSubject,
				'confirmationTemplate' => $form->confirmationTemplate,
				'confirmationBody' => $form->confirmationBody,
				'retentionDays' => $form->retentionDays,
				'retentionMode' => $form->retentionMode,
				'paymentEnabled' => $form->paymentEnabled,
				'paymentBase' => $form->paymentBase,
			],
		];
	}

	/**
	 * Build an unsaved Form from an exported array. The handle is suffixed
	 * until unique, so importing over an existing form never overwrites it.
	 */
	public function createFromExport(array $data): Form
	{
		$settings = (array)($data['settings'] ?? []);

		$form = new Form([
			'name' => (string)($data['name'] ?? 'Imported form'),
			'handle' => $this->uniqueHandle((string)($data['handle'] ?? 'imported')),
			'fields' => (array)($data['fields'] ?? []),
			'recipients' => (string)($settings['recipients'] ?? ''),
			'subject' => (string)($settings['subject'] ?? ''),
			'notificationTemplate' => (string)($settings['notificationTemplate'] ?? ''),
			'notificationIntro' => (string)($settings['notificationIntro'] ?? ''),
			'sendConfirmation' => (bool)($settings['sendConfirmation'] ?? false),
			'confirmationSubject' => (string)($settings['confirmationSubject'] ?? ''),
			'confirmationTemplate' => (string)($settings['confirmationTemplate'] ?? ''),
			'confirmationBody' => (string)($settings['confirmationBody'] ?? ''),
			// Retention: '' = inherit the plugin setting; unknown modes fall back to delete
			'retentionDays' => $settings['retentionDays'] ?? '',
			'retentionMode' => in_array($settings['retentionMode'] ?? '', [Form::RETENTION_MODE_DELETE, Form::RETENTION_MODE_ANONYMIZE], true)
				? $settings['retentionMode']
				: Form::RETENTION_MODE_DELETE,
			'paymentEnabled' => (bool)($settings['paymentEnabled'] ?? false),
			'paymentBase' => (string)($settings['paymentBase'] ?? ''),
		]);

		return $form;
	}

	/**
	 * Duplicate a form. Every field gets a NEW uid (two fields must never
	 * share one — submissions key values by uid), and conditions rules are
	 * remapped from old to new uids so the copied logic keeps working.
	 */
	public function duplicateForm(Form $source): ?Form
	{
		$uidMap = [];
		$fields = $source->fields;

		foreach ($fields as &$field) {
			$new = StringHelper::UUID();
			$uidMap[$field['uid'] ?? ''] = $new;
			$field['uid'] = $new;
		}
		unset($field);

		// Remap condition rule references to the duplicated fields
		foreach ($fields as &$field) {
			foreach ((array)($field['conditions']['rules'] ?? []) as $i => $rule) {
				if (isset($uidMap[$rule['field'] ?? ''])) {
					$field['conditions']['rules'][$i]['field'] = $uidMap[$rule['field']];
				}
			}
		}
		unset($field);

		$copy = clone $source;
		$copy->id = null;
		$copy->uid = null;
		$copy->name = $source->name . ' copy';
		$copy->handle = $this->uniqueHandle($source->handle . 'Copy');
		$copy->fields = $fields;

		return $this->saveForm($copy) ? $copy : null;
	}

	/**
	 * Suffix a handle with a counter until no form claims it.
	 */
	private function uniqueHandle(string $handle): string
	{
		$candidate = $handle;
		$i = 1;

		while (FormRecord::findOne(['handle' => $candidate])) {
			$candidate = $handle . ++$i;
		}

		return $candidate;
	}

	public function deleteFormById(int $id): bool
	{
		$record = FormRecord::findOne($id);

		if (!$record) {
			return false;
		}

		$this->allForms = null;

		return (bool)$record->delete();
	}

	private function createModel(FormRecord $record): Form
	{
		$settings = Json::decodeIfJson($record->settings) ?? [];

		return new Form([
			'id' => $record->id,
			'name' => $record->name,
			'handle' => $record->handle,
			'fields' => Json::decodeIfJson($record->fields) ?? [],
			'recipients' => $settings['recipients'] ?? '',
			'subject' => $settings['subject'] ?? '',
			'notificationTemplate' => $settings['notificationTemplate'] ?? '',
			'notificationIntro' => $settings['notificationIntro'] ?? '',
			'sendConfirmation' => (bool)($settings['sendConfirmation'] ?? false),
			'confirmationSubject' => $settings['confirmationSubject'] ?? '',
			'confirmationTemplate' => $settings['confirmationTemplate'] ?? '',
			'confirmationBody' => $settings['confirmationBody'] ?? '',
			// Retention: '' = inherit the plugin setting (forms saved before
			// this feature existed have no key, so they inherit too)
			'retentionDays' => $settings['retentionDays'] ?? '',
			'retentionMode' => $settings['retentionMode'] ?? Form::RETENTION_MODE_DELETE,
			'paymentEnabled' => (bool)($settings['paymentEnabled'] ?? false),
			'paymentBase' => (string)($settings['paymentBase'] ?? ''),
			'uid' => $record->uid,
		]);
	}
}
