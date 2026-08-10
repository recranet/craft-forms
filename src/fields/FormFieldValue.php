<?php

namespace recranet\forms\fields;

use Craft;
use recranet\forms\models\Form;
use recranet\forms\Plugin;
use Twig\Markup;

/**
 * What templates get from a Form field: renders the form when printed,
 * exposes the Form model for anything else.
 *
 * Extends Twig\Markup so `{{ entry.myFormField }}` outputs the form instead
 * of escaped markup. Twig's escaper returns any Markup instance untouched
 * (Twig\Runtime\EscaperRuntime), and a plain Stringable is not one — so
 * before this, the documented usage printed a page full of &lt;form&gt;.
 * The parent is constructed with empty content because rendering is lazy:
 * __toString() renders on demand, so merely reading the field costs nothing.
 */
class FormFieldValue extends Markup
{
	private ?Form $form = null;
	private bool $resolved = false;

	public function __construct(
		public readonly string $uid,
		private readonly string $class = '',
	) {
		// Content is produced by __toString(); see the class docblock
		parent::__construct('', Craft::$app->charset);
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

	/**
	 * Markup is Countable, and Twig's `empty` test checks Countable BEFORE
	 * anything else — so inheriting the parent's count of the (empty) content
	 * would make `{% if entry.myFormField is not empty %}` always false.
	 * Report presence instead: 1 when a form resolves, 0 when the uid points
	 * at nothing. getForm() memoizes, so this never renders.
	 */
	public function count(): int
	{
		return $this->getForm() ? 1 : 0;
	}

	/**
	 * Serialize as the rendered markup, matching what printing produces.
	 */
	public function jsonSerialize(): string
	{
		return $this->__toString();
	}
}
