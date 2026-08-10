<?php

namespace recranet\forms\controllers;

use Craft;
use craft\web\Controller;
use recranet\forms\elements\Submission;
use recranet\forms\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The editor-managed sender blocklist: list, add, remove.
 *
 * Gated on `recranetForms-viewSubmissions` rather than `manageForms`,
 * because blocking a sender is part of triaging spam — the job of whoever
 * reads the submissions, who is typically not an admin. That is also why
 * this list is a database table and not the plugin setting: settings are
 * project config and read-only on production.
 */
class BlocklistController extends Controller
{
	public function actionIndex(): Response
	{
		$this->requireCpRequest();
		$this->requirePermission('recranetForms-viewSubmissions');

		return $this->renderTemplate('recranet-forms/blocklist/index', [
			'entries' => Plugin::getInstance()->blocklist->getAllEntries(),
			// The config-based half, shown read-only so it is clear why a
			// sender is blocked that has no row here
			'configPatterns' => Plugin::getInstance()->getSettings()->getBlocklist(),
		]);
	}

	/**
	 * Add a pattern. Posted either from the blocklist screen (free text) or
	 * from a submission ("block this sender"), in which case the submission
	 * is recorded as the origin.
	 */
	public function actionAdd(): Response
	{
		$this->requireCpRequest();
		$this->requirePostRequest();
		$this->requirePermission('recranetForms-viewSubmissions');

		$request = Craft::$app->getRequest();
		$pattern = (string)$request->getBodyParam('pattern', '');
		$note = (string)$request->getBodyParam('note', '');
		$submissionId = (int)$request->getBodyParam('submissionId') ?: null;
		$session = Craft::$app->getSession();

		if (Plugin::getInstance()->blocklist->add($pattern, $note, $submissionId)) {
			$session->setSuccess(Craft::t('recranet-forms', 'Sender blocked: {pattern}', ['pattern' => trim($pattern)]));
		} else {
			$session->setError(Craft::t('recranet-forms', 'That sender could not be blocked.'));
		}

		// Back where the action came from: the submission, or the list
		if ($submissionId) {
			$submission = Submission::find()->id($submissionId)->siteId('*')->status(null)->one();

			if ($submission) {
				return $this->redirect($submission->getCpEditUrl());
			}
		}

		return $this->redirect('recranet-forms/blocklist');
	}

	public function actionRemove(): Response
	{
		$this->requireCpRequest();
		$this->requirePostRequest();
		$this->requirePermission('recranetForms-viewSubmissions');

		$id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');

		if (!Plugin::getInstance()->blocklist->remove($id)) {
			throw new NotFoundHttpException('Blocklist entry not found.');
		}

		Craft::$app->getSession()->setSuccess(Craft::t('recranet-forms', 'Entry removed. This sender can submit again.'));

		return $this->redirect('recranet-forms/blocklist');
	}
}
