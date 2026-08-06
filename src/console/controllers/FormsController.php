<?php

namespace recranet\forms\console\controllers;

use craft\console\Controller;
use craft\helpers\Json;
use recranet\forms\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console import/export of form definitions, for seeding new projects:
 *
 *     php craft recranet-forms/forms/export contact > contact.json
 *     php craft recranet-forms/forms/import path/to/contact.json
 */
class FormsController extends Controller
{
	/**
	 * Print a form's JSON export to stdout.
	 */
	public function actionExport(string $handle): int
	{
		$form = Plugin::getInstance()->forms->getFormByHandle($handle);

		if (!$form) {
			$this->stderr("Form \"{$handle}\" not found.\n", Console::FG_RED);

			return ExitCode::DATAERR;
		}

		$this->stdout(Json::encode(
			Plugin::getInstance()->forms->exportForm($form),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
		) . PHP_EOL);

		return ExitCode::OK;
	}

	/**
	 * Import a form from a JSON export file.
	 */
	public function actionImport(string $path): int
	{
		if (!is_file($path)) {
			$this->stderr("File not found: {$path}\n", Console::FG_RED);

			return ExitCode::DATAERR;
		}

		$data = Json::decodeIfJson((string)file_get_contents($path));

		if (!is_array($data) || empty($data['fields'])) {
			$this->stderr("Not a valid form export (missing fields).\n", Console::FG_RED);

			return ExitCode::DATAERR;
		}

		$form = Plugin::getInstance()->forms->createFromExport($data);

		if (!Plugin::getInstance()->forms->saveForm($form)) {
			$this->stderr('Import failed: ' . implode('; ', $form->getFirstErrors()) . "\n", Console::FG_RED);

			return ExitCode::UNSPECIFIED_ERROR;
		}

		$this->stdout("Form \"{$form->name}\" imported with handle \"{$form->handle}\".\n", Console::FG_GREEN);

		return ExitCode::OK;
	}
}
