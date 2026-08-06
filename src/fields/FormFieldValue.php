<?php

namespace recranet\forms\fields;

use Craft;
use recranet\forms\models\Form;
use recranet\forms\Plugin;
use Twig\Markup;

/**
 * What templates get from a Form field: renders the form when printed,
 * exposes the Form model for anything else.
 */
class FormFieldValue implements \Stringable
{
	private ?Form $form = null;
	private bool $resolved = false;

	public function __construct(
		public readonly string $uid,
		private readonly string $class = '',
	) {
	}

	/**
	 * The picked form, or null when it has since been deleted — templates
	 * printing a deleted form get an empty string rather than an error.
	 */
	public function getForm(): ?Form
	{
		if (!$this->resolved) {
			$this->resolved = true;

			foreach (Plugin::getInstance()->forms->getAllForms() as $form) {
				if ($form->uid === $this->uid) {
					$this->form = $form;
					break;
				}
			}
		}

		return $this->form;
	}

	/** Renders the form; extra options merge over the field's settings */
	public function render(array $options = []): Markup
	{
		$form = $this->getForm();

		if (!$form) {
			return new Markup('', Craft::$app->charset);
		}

		$variable = new \recranet\forms\variables\RecranetFormsVariable();

		return $variable->render($form->handle, $options + ['class' => $this->class]);
	}

	public function __toString(): string
	{
		return (string)$this->render();
	}
}
