<?php

namespace elloro\forms;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Elements;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use elloro\forms\elements\Submission;
use elloro\forms\models\Settings;
use elloro\forms\services\Forms;
use elloro\forms\services\Notifications;
use elloro\forms\services\Recaptcha;
use elloro\forms\variables\ElloroFormsVariable;
use yii\base\Event;

/**
 * Elloro Forms plugin.
 *
 * Provides CP-managed forms (field list per form), stored submissions,
 * email notifications and reCAPTCHA v3 spam protection with honest error
 * handling: a misconfigured captcha is surfaced/logged instead of silently
 * marking every submission as spam.
 *
 * @property-read Forms $forms
 * @property-read Recaptcha $recaptcha
 * @property-read Notifications $notifications
 */
class Plugin extends BasePlugin
{
	public string $schemaVersion = '1.0.0';
	public bool $hasCpSettings = true;
	public bool $hasCpSection = true;

	public static function config(): array
	{
		return [
			'components' => [
				'forms' => Forms::class,
				'recaptcha' => Recaptcha::class,
				'notifications' => Notifications::class,
			],
		];
	}

	public function init(): void
	{
		parent::init();

		// Register the Submission element type so it shows up in element queries and the CP
		Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function (RegisterComponentTypesEvent $event) {
			$event->types[] = Submission::class;
		});

		// Expose craft.elloroForms in Twig (form lookup, rendering, recaptcha tag)
		Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event) {
			/** @var CraftVariable $variable */
			$variable = $event->sender;
			$variable->set('elloroForms', ElloroFormsVariable::class);
		});

		if (Craft::$app->getRequest()->getIsCpRequest()) {
			$this->registerCpRoutes();
		}
	}

	/**
	 * CP routes for the form CRUD and submission screens.
	 */
	private function registerCpRoutes(): void
	{
		Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
			$event->rules['elloro-forms'] = 'elloro-forms/forms/index';
			$event->rules['elloro-forms/forms'] = 'elloro-forms/forms/index';
			$event->rules['elloro-forms/forms/new'] = 'elloro-forms/forms/edit';
			$event->rules['elloro-forms/forms/<formId:\d+>'] = 'elloro-forms/forms/edit';
			$event->rules['elloro-forms/submissions'] = 'elloro-forms/submissions/index';
			$event->rules['elloro-forms/submissions/<submissionId:\d+>'] = 'elloro-forms/submissions/view';
		});
	}

	public function getCpNavItem(): ?array
	{
		$item = parent::getCpNavItem();
		$item['label'] = 'Forms';
		$item['subnav'] = [
			'forms' => ['label' => 'Forms', 'url' => 'elloro-forms/forms'],
			'submissions' => ['label' => 'Submissions', 'url' => 'elloro-forms/submissions'],
		];

		return $item;
	}

	protected function createSettingsModel(): ?Model
	{
		return new Settings();
	}

	protected function settingsHtml(): ?string
	{
		return Craft::$app->getView()->renderTemplate('elloro-forms/settings', [
			'settings' => $this->getSettings(),
		]);
	}
}
