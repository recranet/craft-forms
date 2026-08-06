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
			'sendConfirmation' => $form->sendConfirmation,
			'confirmationSubject' => $form->confirmationSubject,
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
			'sendConfirmation' => (bool)($settings['sendConfirmation'] ?? false),
			'confirmationSubject' => $settings['confirmationSubject'] ?? '',
			'uid' => $record->uid,
		]);
	}
}
