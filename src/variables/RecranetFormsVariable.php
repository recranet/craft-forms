<?php

namespace recranet\forms\variables;

use Craft;
use craft\web\View;
use recranet\forms\models\Form;
use recranet\forms\Plugin;
use Twig\Markup;

/**
 * Twig API: craft.recranetForms.*
 */
class RecranetFormsVariable
{
	/**
	 * Look up a form definition by handle.
	 */
	public function form(string $handle): ?Form
	{
		return Plugin::getInstance()->forms->getFormByHandle($handle);
	}

	/**
	 * Render a form with the default template. Projects can override by
	 * creating templates/recranet-forms/form.twig; otherwise the plugin's
	 * built-in Bootstrap-friendly template is used.
	 *
	 * Options: class, buttonLabel, redirect.
	 */
	public function render(string $handle, array $options = []): Markup
	{
		$form = $this->form($handle);

		if (!$form) {
			throw new \InvalidArgumentException("Unknown form handle \"{$handle}\".");
		}

		$view = Craft::$app->getView();
		$variables = ['form' => $form, 'options' => $options] + $this->routeParams();

		if ($view->doesTemplateExist('recranet-forms/form', View::TEMPLATE_MODE_SITE)) {
			$html = $view->renderTemplate('recranet-forms/form', $variables, View::TEMPLATE_MODE_SITE);
		} else {
			$html = $view->renderTemplate('recranet-forms/_render/form', $variables, View::TEMPLATE_MODE_CP);
		}

		return new Markup($html, Craft::$app->charset);
	}

	/**
	 * reCAPTCHA v3 script + hidden input. Include once inside each <form>.
	 * Renders nothing when reCAPTCHA is disabled or the site key is missing
	 * (the back end then logs a config error on submit rather than spamming
	 * every visitor away).
	 */
	public function recaptchaTag(): Markup
	{
		$settings = Plugin::getInstance()->getSettings();
		$siteKey = $settings->getRecaptchaSiteKey();

		if (!$settings->recaptchaEnabled || $siteKey === '') {
			return new Markup('', Craft::$app->charset);
		}

		$view = Craft::$app->getView();
		$html = $view->renderTemplate('recranet-forms/_render/recaptcha', ['siteKey' => $siteKey], View::TEMPLATE_MODE_CP);

		return new Markup($html, Craft::$app->charset);
	}

	/**
	 * Validation errors and old values set by the submit action, so custom
	 * form templates can re-render state after a failed post.
	 */
	private function routeParams(): array
	{
		$params = Craft::$app->getUrlManager()->getRouteParams();

		return [
			'formErrors' => $params['formErrors'] ?? [],
			'formContent' => $params['formContent'] ?? [],
			'erroredFormHandle' => $params['formHandle'] ?? null,
		];
	}
}
