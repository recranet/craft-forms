<?php

namespace recranet\forms\controllers;

use Craft;
use craft\web\Controller;
use recranet\forms\models\Form;
use recranet\forms\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP CRUD for form definitions.
 */
class FormsController extends Controller
{
	public function beforeAction($action): bool
	{
		$this->requireCpRequest();
		$this->requirePermission('accessPlugin-recranet-forms');

		return parent::beforeAction($action);
	}

	public function actionIndex(): Response
	{
		return $this->renderTemplate('recranet-forms/forms/index', [
			'forms' => Plugin::getInstance()->forms->getAllForms(),
		]);
	}

	public function actionEdit(?int $formId = null, ?Form $form = null): Response
	{
		// $form is set when actionSave re-routes here with validation errors
		if ($form === null) {
			$form = $formId ? Plugin::getInstance()->forms->getFormById($formId) : new Form();
		}

		if (!$form) {
			throw new NotFoundHttpException('Form not found.');
		}

		return $this->renderTemplate('recranet-forms/forms/_edit', [
			'form' => $form,
			'fieldTypes' => Form::FIELD_TYPES,
		]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		$formId = $request->getBodyParam('formId');

		$form = $formId
			? Plugin::getInstance()->forms->getFormById((int)$formId)
			: new Form();

		if (!$form) {
			throw new NotFoundHttpException('Form not found.');
		}

		$form->name = $request->getBodyParam('name');
		$form->handle = $request->getBodyParam('handle');
		$form->recipients = (string)$request->getBodyParam('recipients', '');
		$form->subject = (string)$request->getBodyParam('subject', '');
		$form->sendConfirmation = (bool)$request->getBodyParam('sendConfirmation', false);
		$form->confirmationSubject = (string)$request->getBodyParam('confirmationSubject', '');
		$form->fields = $this->normalizeFieldRows((array)$request->getBodyParam('fields', []));

		if (!Plugin::getInstance()->forms->saveForm($form)) {
			Craft::$app->getSession()->setError('Couldn’t save form.');
			Craft::$app->getUrlManager()->setRouteParams(['form' => $form]);

			return null;
		}

		Craft::$app->getSession()->setNotice('Form saved.');

		return $this->redirectToPostedUrl($form);
	}

	public function actionDelete(): Response
	{
		$this->requirePostRequest();

		$formId = (int)Craft::$app->getRequest()->getRequiredBodyParam('formId');
		Plugin::getInstance()->forms->deleteFormById($formId);
		Craft::$app->getSession()->setNotice('Form deleted.');

		return $this->redirect('recranet-forms/forms');
	}

	/**
	 * Normalize the EditableTable rows into the field definition shape.
	 */
	private function normalizeFieldRows(array $rows): array
	{
		$fields = [];

		foreach ($rows as $row) {
			// Skip fully empty rows the editable table may post
			if (empty($row['handle']) && empty($row['label'])) {
				continue;
			}

			$fields[] = [
				// Existing uid carries the field's identity across saves;
				// empty for new cards (assigned in Forms::saveForm)
				'uid' => trim((string)($row['uid'] ?? '')),
				'handle' => trim((string)($row['handle'] ?? '')),
				'label' => trim((string)($row['label'] ?? '')),
				'type' => $row['type'] ?? 'text',
				'required' => (bool)($row['required'] ?? false),
				'options' => trim((string)($row['options'] ?? '')),
				'width' => in_array($row['width'] ?? '', ['full', 'half'], true) ? $row['width'] : 'full',
			];
		}

		return $fields;
	}
}
