<?php

namespace recranet\forms\variables;

use Craft;
use craft\helpers\Html;
use craft\web\View;
use recranet\forms\FormFields;
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

		// Visitor-facing text comes from the current site's translation when
		// the editor made one; untranslated strings keep the source wording
		$form = Plugin::getInstance()->formTranslations->applyTo($form);

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
	 * Anti-spam fields + captcha widget. Include once inside each <form>:
	 * renders the hashed render-timestamp (submit-timing check) and the
	 * configured captcha provider's widget, its token bound to $action
	 * (defaults to the form handle in the default template) so tokens can't
	 * be replayed across forms or sites.
	 */
	public function captchaTag(?string $action = null): Markup
	{
		// Timestamp field always renders — the timing check works without a captcha
		$html = Html::hiddenInput(
			FormFields::TIMESTAMP,
			Craft::$app->getSecurity()->hashData((string)time()),
		);

		// One-time token (opt-in): a fresh nonce per render, consumed by the
		// first submit; a re-submit with the same nonce is flagged as a replay
		if (Plugin::getInstance()->getSettings()->oneTimeSubmitTokens) {
			$html .= Html::hiddenInput(
				FormFields::SUBMIT_TOKEN,
				Craft::$app->getSecurity()->hashData(bin2hex(random_bytes(16))),
			);
		}

		$captcha = Plugin::getInstance()->spam->getCaptcha();

		if ($captcha) {
			$html .= $captcha->render($action);
		}

		return new Markup($html, Craft::$app->charset);
	}

	/**
	 * @deprecated Use captchaTag() — kept so pre-2.1 templates keep working.
	 */
	public function recaptchaTag(): Markup
	{
		return $this->captchaTag();
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
