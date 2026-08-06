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
		$this->requirePermission('recranetForms-manageForms');

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
			'emailTemplates' => $this->findEmailTemplates(),
		]);
	}

	/**
	 * Site templates that can serve as a per-form mail template override,
	 * offered in the form's mail settings as a dropdown so editors never
	 * have to type template paths. Scans the conventional email locations.
	 *
	 * @return string[] template paths relative to templates/, without extension
	 */
	private function findEmailTemplates(): array
	{
		$base = Craft::$app->getPath()->getSiteTemplatesPath();
		$templates = [];

		foreach (['recranet-forms/_emails', '_emails'] as $dir) {
			foreach (glob("{$base}/{$dir}/*.twig") ?: [] as $file) {
				$name = basename($file, '.twig');

				// Layout partials are extended by mail templates, not used directly
				if (str_starts_with($name, '_layout')) {
					continue;
				}

				$templates[] = "{$dir}/{$name}";
			}
		}

		return $templates;
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
		$form->notificationTemplate = (string)$request->getBodyParam('notificationTemplate', '');
		$form->notificationIntro = (string)$request->getBodyParam('notificationIntro', '');
		$form->sendConfirmation = (bool)$request->getBodyParam('sendConfirmation', false);
		$form->confirmationSubject = (string)$request->getBodyParam('confirmationSubject', '');
		$form->confirmationTemplate = (string)$request->getBodyParam('confirmationTemplate', '');
		// Empty string = inherit the plugin-wide retention setting
		$form->retentionDays = (string)$request->getBodyParam('retentionDays', '');
		$form->retentionMode = (string)$request->getBodyParam('retentionMode', Form::RETENTION_MODE_DELETE);
		$form->confirmationBody = (string)$request->getBodyParam('confirmationBody', '');
		$form->fields = $this->normalizeFieldRows((array)$request->getBodyParam('fields', []));

		if (!Plugin::getInstance()->forms->saveForm($form)) {
			Craft::$app->getSession()->setError('Couldn’t save form.');
			Craft::$app->getUrlManager()->setRouteParams(['form' => $form]);

			return null;
		}

		Craft::$app->getSession()->setNotice('Form saved.');

		return $this->redirectToPostedUrl($form);
	}

	/**
	 * Preview what this form produces, without sending or storing anything:
	 * the rendered front-end form, or either email with sample values.
	 * Loaded in an iframe from the form edit screen.
	 *
	 * The email previews go through the exact same template resolution as a
	 * real send (per-form override → site override → plugin default), so
	 * "Default template" is something an editor can look at before deciding
	 * to override it.
	 */
	public function actionPreview(int $formId, string $type = 'form'): Response
	{
		$form = Plugin::getInstance()->forms->getFormById($formId);

		if (!$form) {
			throw new NotFoundHttpException('Form not found.');
		}

		$view = Craft::$app->getView();

		if ($type === 'form') {
			// Render the front-end template exactly as the site would
			$body = (string)Plugin::getInstance()->forms->renderFormPreview($form);
			$title = Craft::t('recranet-forms', 'Form preview');
			// Bootstrap from the CDN: the plugin's default markup is Bootstrap 5,
			// and the project's own compiled CSS isn't reachable from the CP
			$head = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">';
			$note = Craft::t('recranet-forms', 'Styled with stock Bootstrap 5 — your site’s own CSS may look different.');
		} else {
			$submission = Plugin::getInstance()->forms->sampleSubmission($form);
			$body = Plugin::getInstance()->notifications->previewEmail($form, $submission, $type);
			$subject = Plugin::getInstance()->notifications->previewSubject($form, $submission, $type);
			$title = Craft::t('recranet-forms', 'Email preview');
			$head = '';
			$note = Craft::t('recranet-forms', 'Subject: {subject}', ['subject' => $subject])
				. ' — ' . Craft::t('recranet-forms', 'Sample values, nothing was sent or stored.');
		}

		$html = $view->renderTemplate('recranet-forms/_preview', [
			'title' => $title,
			'note' => $note,
			'head' => $head,
			'body' => $body,
		]);

		return $this->asRaw($html);
	}

	/**
	 * Download a form definition as JSON (fields incl. uids + mail settings).
	 */
	public function actionExport(int $formId): Response
	{
		$form = Plugin::getInstance()->forms->getFormById($formId);

		if (!$form) {
			throw new NotFoundHttpException('Form not found.');
		}

		$json = \craft\helpers\Json::encode(
			Plugin::getInstance()->forms->exportForm($form),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
		);

		return $this->response->sendContentAsFile($json, "form-{$form->handle}.json", [
			'mimeType' => 'application/json',
		]);
	}

	/**
	 * Import screen (paste or upload a form JSON export).
	 */
	public function actionImportScreen(): Response
	{
		return $this->renderTemplate('recranet-forms/forms/_import');
	}

	public function actionImport(): ?Response
	{
		$this->requirePostRequest();

		$json = trim((string)Craft::$app->getRequest()->getBodyParam('json', ''));

		// An uploaded file wins over the textarea
		$upload = \craft\web\UploadedFile::getInstanceByName('file');

		if ($upload && !$upload->getHasError()) {
			$json = (string)file_get_contents($upload->tempName);
		}

		$data = \craft\helpers\Json::decodeIfJson($json);

		if (!is_array($data) || empty($data['fields'])) {
			Craft::$app->getSession()->setError('Not a valid form export (missing fields).');

			return null;
		}

		$form = Plugin::getInstance()->forms->createFromExport($data);

		if (!Plugin::getInstance()->forms->saveForm($form)) {
			Craft::$app->getSession()->setError('Couldn’t import form: ' . implode('; ', $form->getFirstErrors()));

			return null;
		}

		Craft::$app->getSession()->setNotice("Form \"{$form->name}\" imported.");

		return $this->redirect('recranet-forms/forms/' . $form->id);
	}

	/**
	 * Duplicate a form (new field uids, conditions remapped).
	 */
	public function actionDuplicate(): Response
	{
		$this->requirePostRequest();

		$formId = (int)Craft::$app->getRequest()->getRequiredBodyParam('formId');
		$source = Plugin::getInstance()->forms->getFormById($formId);

		if (!$source) {
			throw new NotFoundHttpException('Form not found.');
		}

		$copy = Plugin::getInstance()->forms->duplicateForm($source);

		if (!$copy) {
			Craft::$app->getSession()->setError('Couldn’t duplicate form.');

			return $this->redirect('recranet-forms/forms');
		}

		Craft::$app->getSession()->setNotice("Form duplicated as \"{$copy->name}\".");

		return $this->redirect('recranet-forms/forms/' . $copy->id);
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

			// The builder serializes the conditional-visibility rules to JSON
			// in a hidden input; decode defensively (malformed = no rules)
			$conditions = null;

			if (!empty($row['conditions'])) {
				$decoded = \craft\helpers\Json::decodeIfJson((string)$row['conditions']);

				if (is_array($decoded) && !empty($decoded['rules'])) {
					$conditions = $decoded;
				}
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
				'placeholder' => trim((string)($row['placeholder'] ?? '')),
				'description' => trim((string)($row['description'] ?? '')),
				'adminLabel' => trim((string)($row['adminLabel'] ?? '')),
				'defaultValue' => trim((string)($row['defaultValue'] ?? '')),
				'errorMessage' => trim((string)($row['errorMessage'] ?? '')),
				'conditions' => $conditions,
			];
		}

		return $fields;
	}
}
