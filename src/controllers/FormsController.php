<?php

namespace recranet\forms\controllers;

use Craft;
use craft\web\Controller;
use recranet\forms\jobs\TranslateFormJob;
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

		// The primary site holds the source form; every other site edits only
		// that site's translations of it (structure stays shared)
		$sites = Craft::$app->getSites();
		$primarySite = $sites->getPrimarySite();
		// ?site=<handle> is how the whole CP switches sites (Cp::siteMenuItems
		// builds its links that way), so the breadcrumb site menu just works
		$siteHandle = Craft::$app->getRequest()->getQueryParam('site');
		$editedSite = $siteHandle ? $sites->getSiteByHandle($siteHandle) : $primarySite;

		if (!$editedSite) {
			throw new NotFoundHttpException('Site not found.');
		}

		$translations = Plugin::getInstance()->formTranslations;
		$paymentProvider = Plugin::getInstance()->payments->getProvider();

		return $this->renderTemplate('recranet-forms/forms/_edit', [
			'paymentsAvailable' => $paymentProvider !== null,
			'paymentsProviderName' => $paymentProvider?->getName() ?? '',
			'paymentsTestMode' => $paymentProvider?->isTestMode() ?? false,
			'form' => $form,
			'fieldTypes' => Form::FIELD_TYPES,
			'layoutTypes' => Form::LAYOUT_TYPES,
			'emailTemplates' => $this->findEmailTemplates(),
			'editedSite' => $editedSite,
			'primarySite' => $primarySite,
			'isTranslation' => $editedSite->id !== $primarySite->id,
			'editableSites' => $sites->getEditableSites(),
			'translations' => $form->id ? $translations->get($form->id, $editedSite->id) : [],
			'translationProgress' => $form->id ? $translations->progress($form, $editedSite->id) : null,
			'aiAvailable' => Plugin::getInstance()->aiTranslate->isAvailable(),
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

		// A translation site posts only translated strings; the source form
		// (structure, handles, settings) is edited on the primary site
		$siteHandle = $request->getBodyParam('siteHandle');
		$editedSite = $siteHandle ? Craft::$app->getSites()->getSiteByHandle($siteHandle) : null;

		if ($editedSite && $editedSite->id !== Craft::$app->getSites()->getPrimarySite()->id) {
			if (!$form->id) {
				throw new NotFoundHttpException('Form not found.');
			}

			Plugin::getInstance()->formTranslations->save(
				$form->id,
				$editedSite->id,
				(array)$request->getBodyParam('translations', []),
			);

			Craft::$app->getSession()->setNotice(Craft::t('recranet-forms', 'Translations saved.'));

			return $this->redirectToPostedUrl($form);
		}

		$form->name = $request->getBodyParam('name');
		$form->handle = $request->getBodyParam('handle');
		$form->recipients = (string)$request->getBodyParam('recipients', '');
		$form->subject = (string)$request->getBodyParam('subject', '');
		$form->notificationTemplate = (string)$request->getBodyParam('notificationTemplate', '');
		$form->notificationIntro = (string)$request->getBodyParam('notificationIntro', '');
		$form->extraNotifications = Plugin::getInstance()->forms->normalizeExtraNotifications((array)$request->getBodyParam('extraNotifications', []));
		$form->sendConfirmation = (bool)$request->getBodyParam('sendConfirmation', false);
		$form->confirmationSubject = (string)$request->getBodyParam('confirmationSubject', '');
		$form->confirmationTemplate = (string)$request->getBodyParam('confirmationTemplate', '');
		// Empty string = inherit the plugin-wide retention setting
		$form->retentionDays = (string)$request->getBodyParam('retentionDays', '');
		$form->retentionMode = (string)$request->getBodyParam('retentionMode', Form::RETENTION_MODE_DELETE);
		$form->paymentEnabled = (bool)$request->getBodyParam('paymentEnabled', false);
		$form->paymentBase = trim((string)$request->getBodyParam('paymentBase', ''));
		$form->successBehavior = (string)$request->getBodyParam('successBehavior', Form::SUCCESS_MESSAGE);
		$form->successMessage = trim((string)$request->getBodyParam('successMessage', ''));
		$form->successRedirect = trim((string)$request->getBodyParam('successRedirect', ''));
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

		// The CP's ?site= param sets the current site, so an editor previewing
		// a translation site sees the translated wording — same applyTo() the
		// real render and the real sends go through
		$form = Plugin::getInstance()->formTranslations->applyTo($form);

		$view = Craft::$app->getView();

		if ($type === 'form') {
			// Render the front-end template exactly as the site would. The
			// preview page brings its own neutral stylesheet: the project's
			// compiled CSS isn't reachable from the CP, and the point of this
			// preview is the structure and wording, not the site's design
			$body = (string)Plugin::getInstance()->forms->renderFormPreview($form);
			$title = Craft::t('recranet-forms', 'Form preview');
			$note = Craft::t('recranet-forms', 'Shown with neutral styling, without your site’s own design — this preview is about the fields, order and wording.');
		} else {
			$submission = Plugin::getInstance()->forms->sampleSubmission($form);
			$body = Plugin::getInstance()->notifications->previewEmail($form, $submission, $type);
			$subject = Plugin::getInstance()->notifications->previewSubject($form, $submission, $type);
			$title = Craft::t('recranet-forms', 'Email preview');
			$note = Craft::t('recranet-forms', 'Subject: {subject}', ['subject' => $subject])
				. ' — ' . Craft::t('recranet-forms', 'Sample values, nothing was sent or stored.');
		}

		$html = $view->renderTemplate('recranet-forms/_preview', [
			'title' => $title,
			'note' => $note,
			'withFormCss' => $type === 'form',
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
	 * Import screen: start from a bundled stencil, or paste/upload a form
	 * JSON export.
	 */
	public function actionImportScreen(): Response
	{
		return $this->renderTemplate('recranet-forms/forms/_import', [
			'stencils' => $this->stencils(),
		]);
	}

	/**
	 * Create a form from one of the bundled stencils — a regular JSON export
	 * that ships with the plugin, so a new project starts with sane fields,
	 * subjects and confirmation texts instead of an empty canvas.
	 */
	public function actionApplyStencil(): Response
	{
		$this->requirePostRequest();

		$stencil = (string)Craft::$app->getRequest()->getRequiredBodyParam('stencil');
		$stencils = $this->stencils();

		if (!isset($stencils[$stencil])) {
			throw new NotFoundHttpException('Stencil not found.');
		}

		$data = \craft\helpers\Json::decodeIfJson((string)file_get_contents($stencils[$stencil]['path']));
		$form = Plugin::getInstance()->forms->createFromExport((array)$data);

		if (!Plugin::getInstance()->forms->saveForm($form)) {
			Craft::$app->getSession()->setError('Couldn’t create the form: ' . implode('; ', $form->getFirstErrors()));

			return $this->redirect('recranet-forms/forms/import');
		}

		Craft::$app->getSession()->setNotice(Craft::t('recranet-forms', 'Form "{name}" created — make it yours.', ['name' => $form->name]));

		return $this->redirect('recranet-forms/forms/' . $form->id);
	}

	/**
	 * The bundled stencils: name (from the JSON) keyed by file basename.
	 *
	 * @return array<string, array{name: string, path: string}>
	 */
	private function stencils(): array
	{
		$stencils = [];

		foreach (glob(__DIR__ . '/../stencils/*.json') ?: [] as $path) {
			$data = \craft\helpers\Json::decodeIfJson((string)file_get_contents($path));

			if (is_array($data) && !empty($data['name'])) {
				$stencils[basename($path, '.json')] = ['name' => (string)$data['name'], 'path' => $path];
			}
		}

		return $stencils;
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
	 * Queue machine translation of a form's missing strings for one site,
	 * using the AI Translator plugin's provider (so the project's glossary
	 * and tone-of-voice apply). Existing translations are never overwritten.
	 *
	 * Queued rather than run inline: a form's translation is a real LLM round
	 * trip, too slow for a CP request. Whether anything is missing is checked
	 * here first — that's a local DB lookup, and "nothing to do" as instant
	 * feedback beats a no-op job in the queue.
	 */
	public function actionTranslate(): Response
	{
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		$formId = (int)$request->getRequiredBodyParam('formId');
		$siteId = (int)$request->getRequiredBodyParam('siteId');

		$form = Plugin::getInstance()->forms->getFormById($formId);

		if (!$form) {
			throw new NotFoundHttpException('Form not found.');
		}

		$progress = Plugin::getInstance()->formTranslations->progress($form, $siteId);

		if ($progress['translated'] >= $progress['total']) {
			Craft::$app->getSession()->setNotice(Craft::t('recranet-forms', 'Nothing left to translate.'));

			return $this->redirectToPostedUrl();
		}

		Craft::$app->getQueue()->push(new TranslateFormJob([
			'formId' => $formId,
			'targetSiteId' => $siteId,
		]));

		Craft::$app->getSession()->setNotice(Craft::t('recranet-forms', 'Translation queued — the missing strings appear once the queue has run.'));

		return $this->redirectToPostedUrl();
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

		foreach ($rows as $i => $row) {
			$isLayout = in_array($row['type'] ?? '', Form::LAYOUT_TYPES, true);

			// Skip fully empty rows the editable table may post — but a layout
			// row (divider, unlabeled paragraph) legitimately has no label
			if (empty($row['handle']) && empty($row['label']) && !$isLayout) {
				continue;
			}

			// Layout rows without a handle get one, so validation (which keys
			// on handles) passes without the editor inventing one for an <hr>
			if ($isLayout && empty($row['handle'])) {
				$row['handle'] = ($row['type'] ?? 'layout') . ($i + 1);
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
				// Payment pricing: per-option list on choice fields, per-unit
				// price on number fields — only meaningful when the form has
				// payment enabled, harmless otherwise
				'prices' => trim((string)($row['prices'] ?? '')),
				'price' => trim((string)($row['price'] ?? '')),
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
