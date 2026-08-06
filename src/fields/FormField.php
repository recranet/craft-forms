<?php

namespace recranet\forms\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Cp;
use recranet\forms\models\Form;
use recranet\forms\Plugin;
use Twig\Markup;

/**
 * A Craft field that lets an author pick one of the CP-managed forms and
 * drop it into page content — an entry's body/Matrix, a CKEditor entry
 * block, wherever a field can live.
 *
 * The stored value is the form's **uid**, not its handle or id: uids survive
 * a handle rename and travel with a JSON export/import, so content keeps
 * pointing at the right form. Templates get a ready-to-render value:
 *
 *     {{ entry.contactForm }}            {# renders the form #}
 *     {{ entry.contactForm.form.name }}  {# the Form model, when you need it #}
 */
class FormField extends Field
{
	/** Render options passed to craft.recranetForms.render() */
	public string $formClass = '';

	public static function displayName(): string
	{
		return Craft::t('recranet-forms', 'Form');
	}

	public static function icon(): string
	{
		return 'square-check';
	}

	public static function phpType(): string
	{
		return FormFieldValue::class . '|null';
	}

	/** Stored as the form's uid */
	public static function dbType(): string
	{
		return 'string';
	}

	/**
	 * Value in = the stored uid (or a posted uid); value out = a small
	 * wrapper that renders itself in Twig and exposes the Form model.
	 */
	public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
	{
		if ($value instanceof FormFieldValue) {
			return $value;
		}

		$uid = is_string($value) ? trim($value) : '';

		return $uid !== '' ? new FormFieldValue($uid, $this->formClass) : null;
	}

	public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
	{
		return $value instanceof FormFieldValue ? $value->uid : null;
	}

	protected function inputHtml(mixed $value, ?ElementInterface $element = null, bool $inline = false): string
	{
		$options = [['label' => Craft::t('recranet-forms', 'None'), 'value' => '']];

		foreach (Plugin::getInstance()->forms->getAllForms() as $form) {
			$options[] = ['label' => $form->name, 'value' => $form->uid];
		}

		return Cp::selectHtml([
			'id' => $this->getInputId(),
			'name' => $this->handle,
			'value' => $value instanceof FormFieldValue ? $value->uid : '',
			'options' => $options,
		]);
	}

	public function getSettingsHtml(): ?string
	{
		return Cp::textFieldHtml([
			'label' => Craft::t('recranet-forms', 'CSS class'),
			'instructions' => Craft::t('recranet-forms', 'Added to the rendered <form> element.'),
			'id' => 'formClass',
			'name' => 'formClass',
			'value' => $this->formClass,
		]);
	}

	/** Searchable as the form's name, so content search finds the page */
	public function getSearchKeywords(mixed $value, ElementInterface $element): string
	{
		return $value instanceof FormFieldValue ? (string)$value->getForm()?->name : '';
	}
}
