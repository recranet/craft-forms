<?php

namespace recranet\forms;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use recranet\forms\elements\Submission;
use recranet\forms\models\Settings;
use recranet\forms\services\Forms;
use recranet\forms\services\Notifications;
use recranet\forms\services\Retention;
use recranet\forms\services\SpamService;
use recranet\forms\utilities\EmailTestUtility;
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
 * @property-read SpamService $spam
 * @property-read Notifications $notifications
 * @property-read Retention $retention
 */
class Plugin extends BasePlugin
{
	public string $schemaVersion = '2.1.0';
	public bool $hasCpSettings = true;
	public bool $hasCpSection = true;

	/** Log an error under the plugin's own category */
	public static function error(string $message): void
	{
		Craft::error($message, 'recranet-forms');
	}

	/** Log an informational message under the plugin's own category */
	public static function info(string $message): void
	{
		Craft::info($message, 'recranet-forms');
	}

	public static function config(): array
	{
		return [
			'components' => [
				'forms' => Forms::class,
				'spam' => SpamService::class,
				'notifications' => Notifications::class,
				'retention' => Retention::class,
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

		// Expose craft.recranetForms in Twig (form lookup, rendering, captcha tag)
		Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event) {
			/** @var CraftVariable $variable */
			$variable = $event->sender;
			$variable->set('recranetForms', RecranetFormsVariable::class);
		});

		// Email / SMTP test utility (works with allowAdminChanges disabled)
		Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function (RegisterComponentTypesEvent $event) {
			$event->types[] = EmailTestUtility::class;
		});

		// Retention: prune old submissions whenever Craft's GC runs
		Event::on(Gc::class, Gc::EVENT_RUN, function () {
			$this->retention->pruneSubmissions();
		});

		// Granular permissions on top of Craft's accessPlugin- gate: a role
		// can read submissions without being able to change form definitions
		Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function (RegisterUserPermissionsEvent $event) {
			$event->permissions[] = [
				'heading' => 'Recranet Forms',
				'permissions' => [
					'recranetForms-manageForms' => [
						'label' => Craft::t('recranet-forms', 'Manage forms (create, edit, delete form definitions)'),
					],
					'recranetForms-viewSubmissions' => [
						'label' => Craft::t('recranet-forms', 'View submissions'),
						'nested' => [
							'recranetForms-deleteSubmissions' => ['label' => Craft::t('recranet-forms', 'Delete submissions')],
						],
					],
				],
			];
		});

		if (Craft::$app->getRequest()->getIsCpRequest()) {
			$this->registerCpRoutes();
		} else {
			$this->registerSiteRoutes();
		}
	}

	/**
	 * Front-end route for the tokenized self-service view (AVG/GDPR): the
	 * confirmation email can link the submitter to their own submission,
	 * where they can also delete it — no login, the unguessable token is
	 * the credential.
	 */
	private function registerSiteRoutes(): void
	{
		Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function (RegisterUrlRulesEvent $event) {
			$event->rules['recranet-forms/submission/<token:[A-Za-z0-9]{32}>'] = 'recranet-forms/submissions/view-by-token';
		});
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
			$event->rules['recranet-forms/forms/import'] = 'recranet-forms/forms/import-screen';
			$event->rules['recranet-forms/forms/<formId:\d+>'] = 'recranet-forms/forms/edit';
			$event->rules['recranet-forms/forms/<formId:\d+>/export'] = 'recranet-forms/forms/export';
			$event->rules['recranet-forms/submissions'] = 'recranet-forms/submissions/index';
			$event->rules['recranet-forms/submissions/<submissionId:\d+>'] = 'recranet-forms/submissions/view';
		});
	}

	public function getCpNavItem(): ?array
	{
		$item = parent::getCpNavItem();
		$item['label'] = Craft::t('recranet-forms', 'Forms');
		$item['subnav'] = [
			'forms' => ['label' => Craft::t('recranet-forms', 'Forms'), 'url' => 'recranet-forms/forms'],
			'submissions' => ['label' => Craft::t('recranet-forms', 'Submissions'), 'url' => 'recranet-forms/submissions'],
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
