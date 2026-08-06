<?php

namespace recranet\forms;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Elements;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use recranet\forms\elements\Submission;
use recranet\forms\models\Settings;
use recranet\forms\services\Forms;
use recranet\forms\services\Notifications;
use recranet\forms\services\Recaptcha;
use recranet\forms\variables\RecranetFormsVariable;
use yii\base\Event;

/**
 * Recranet Forms plugin.
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
	public string $schemaVersion = '2.0.0';
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

		// Expose craft.recranetForms in Twig (form lookup, rendering, recaptcha tag)
		Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event) {
			/** @var CraftVariable $variable */
			$variable = $event->sender;
			$variable->set('recranetForms', RecranetFormsVariable::class);
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
			$event->rules['recranet-forms'] = 'recranet-forms/forms/index';
			$event->rules['recranet-forms/forms'] = 'recranet-forms/forms/index';
			$event->rules['recranet-forms/forms/new'] = 'recranet-forms/forms/edit';
			$event->rules['recranet-forms/forms/<formId:\d+>'] = 'recranet-forms/forms/edit';
			$event->rules['recranet-forms/submissions'] = 'recranet-forms/submissions/index';
			$event->rules['recranet-forms/submissions/<submissionId:\d+>'] = 'recranet-forms/submissions/view';
		});
	}

	public function getCpNavItem(): ?array
	{
		$item = parent::getCpNavItem();
		$item['label'] = 'Forms';
		$item['subnav'] = [
			'forms' => ['label' => 'Forms', 'url' => 'recranet-forms/forms'],
			'submissions' => ['label' => 'Submissions', 'url' => 'recranet-forms/submissions'],
		];

		return $item;
	}

	/**
	 * Craft writes elementSources.<Submission FQCN> to project config the
	 * moment an admin customizes the submissions index columns; clean it up
	 * on uninstall so it doesn't linger and drift on environments where
	 * allowAdminChanges is off.
	 */
	protected function beforeUninstall(): void
	{
		Craft::$app->getProjectConfig()->remove('elementSources.' . Submission::class);

		parent::beforeUninstall();
	}

	protected function createSettingsModel(): ?Model
	{
		return new Settings();
	}

	protected function settingsHtml(): ?string
	{
		return Craft::$app->getView()->renderTemplate('recranet-forms/settings', [
			'settings' => $this->getSettings(),
		]);
	}
}
