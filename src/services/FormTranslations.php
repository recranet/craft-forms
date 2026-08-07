<?php

namespace recranet\forms\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use recranet\forms\models\Form;
use recranet\forms\Plugin;
use yii\base\Component;

/**
 * Per-site translations of form content.
 *
 * Why this exists: form labels are typed by editors in the CP, so they can't
 * live in `translations/{locale}/site.php` — that would need a developer and
 * a deploy for every wording change. They're content, so they're stored as
 * content: one row per (form, site) holding only the strings that differ
 * from the source form.
 *
 * Translatable keys (flat, so a translation row is easy to diff and to feed
 * to a translation API in one batch):
 *
 *   name
 *   subject | notificationIntro | confirmationSubject | confirmationBody
 *   field.<uid>.label | .placeholder | .description | .options | .errorMessage
 *
 * Structure (type, handle, width, required, conditions) is deliberately NOT
 * translatable: a translated form must stay the same form, or submissions
 * from different sites would no longer be comparable.
 */
class FormTranslations extends Component
{
	private const TABLE = '{{%recranetforms_form_translations}}';

	/** Per-field keys that carry visitor-facing text */
	private const FIELD_KEYS = ['label', 'placeholder', 'description', 'options', 'errorMessage'];

	/** Form-level keys that carry visitor- or owner-facing text */
	private const FORM_KEYS = ['name', 'subject', 'notificationIntro', 'confirmationSubject', 'confirmationBody', 'successMessage'];

	/** @var array<string, array<string, string>> Memoized per "formId:siteId" */
	private array $cache = [];

	/**
	 * The source strings of a form, flat and keyed. Empty values are kept out
	 * so an untranslated form doesn't look like it has dozens of TODOs.
	 *
	 * @return array<string, string>
	 */
	public function sourceStrings(Form $form): array
	{
		$strings = [];

		foreach (self::FORM_KEYS as $key) {
			$value = trim((string)($form->$key ?? ''));

			if ($value !== '') {
				$strings[$key] = $value;
			}
		}

		foreach ($form->fields as $field) {
			$uid = $field['uid'] ?? null;

			if (!$uid) {
				continue;
			}

			foreach (self::FIELD_KEYS as $key) {
				$value = trim((string)($field[$key] ?? ''));

				if ($value !== '') {
					$strings["field.{$uid}.{$key}"] = $value;
				}
			}
		}

		return $strings;
	}

	/**
	 * Stored translations for a form on a site (empty array when none).
	 *
	 * @return array<string, string>
	 */
	public function get(int $formId, int $siteId): array
	{
		$cacheKey = "{$formId}:{$siteId}";

		if (!isset($this->cache[$cacheKey])) {
			$json = (new Query())
				->select(['translations'])
				->from(self::TABLE)
				->where(['formId' => $formId, 'siteId' => $siteId])
				->scalar();

			$this->cache[$cacheKey] = $json ? (Json::decodeIfJson($json) ?: []) : [];
		}

		return $this->cache[$cacheKey];
	}

	/**
	 * Store translations for a form on a site. Empty values are dropped, so
	 * clearing a field falls back to the source text rather than blanking it.
	 *
	 * @param array<string, string> $translations
	 */
	public function save(int $formId, int $siteId, array $translations): bool
	{
		$clean = [];

		foreach ($translations as $key => $value) {
			$value = trim((string)$value);

			if ($value !== '') {
				$clean[$key] = $value;
			}
		}

		unset($this->cache["{$formId}:{$siteId}"]);
		$now = Db::prepareDateForDb(new \DateTime());
		$exists = (new Query())->from(self::TABLE)->where(['formId' => $formId, 'siteId' => $siteId])->exists();

		if ($exists) {
			return (bool)Craft::$app->getDb()->createCommand()
				->update(self::TABLE, ['translations' => Json::encode($clean), 'dateUpdated' => $now], [
					'formId' => $formId,
					'siteId' => $siteId,
				])
				->execute();
		}

		return (bool)Craft::$app->getDb()->createCommand()
			->insert(self::TABLE, [
				'formId' => $formId,
				'siteId' => $siteId,
				'translations' => Json::encode($clean),
				'dateCreated' => $now,
				'dateUpdated' => $now,
				'uid' => \craft\helpers\StringHelper::UUID(),
			])
			->execute();
	}

	/**
	 * A copy of the form with its translations for the given site applied.
	 * Anything untranslated keeps the source text, so a half-translated form
	 * still renders completely.
	 *
	 * This is what the front end, the emails and the previews use — templates
	 * keep reading `form.fields[…].label` and get the right language.
	 */
	public function applyTo(Form $form, ?int $siteId = null): Form
	{
		$siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

		if (!$form->id) {
			return $form;
		}

		$translations = $this->get($form->id, $siteId);

		if ($translations === []) {
			return $form;
		}

		$translated = clone $form;

		foreach (self::FORM_KEYS as $key) {
			if (!empty($translations[$key])) {
				$translated->$key = $translations[$key];
			}
		}

		$fields = $translated->fields;

		foreach ($fields as $i => $field) {
			$uid = $field['uid'] ?? null;

			if (!$uid) {
				continue;
			}

			foreach (self::FIELD_KEYS as $key) {
				$value = $translations["field.{$uid}.{$key}"] ?? null;

				if ($value !== null && $value !== '') {
					$fields[$i][$key] = $value;
				}
			}
		}

		$translated->fields = $fields;

		return $translated;
	}

	/**
	 * How many of a form's source strings have a translation on a site —
	 * drives the "3/12 translated" hint on the site switcher.
	 *
	 * @return array{translated: int, total: int}
	 */
	public function progress(Form $form, int $siteId): array
	{
		$source = $this->sourceStrings($form);
		$translations = $form->id ? $this->get($form->id, $siteId) : [];
		$translated = 0;

		foreach (array_keys($source) as $key) {
			if (!empty($translations[$key])) {
				$translated++;
			}
		}

		return ['translated' => $translated, 'total' => count($source)];
	}

	/**
	 * Fill a site's missing translations with machine translations, using the
	 * in-house AI Translator plugin's provider (so the project's glossary and
	 * tone-of-voice settings apply). Existing translations are left alone —
	 * an editor's wording always wins over the machine's.
	 *
	 * @return int number of strings translated
	 */
	public function translateWithAi(int $formId, int $targetSiteId): int
	{
		$form = Plugin::getInstance()->forms->getFormById($formId);

		if (!$form) {
			throw new \RuntimeException("Form {$formId} not found.");
		}

		$existing = $this->get($formId, $targetSiteId);
		$missing = array_diff_key($this->sourceStrings($form), array_filter($existing));

		if ($missing === []) {
			return 0;
		}

		$sourceSiteId = Craft::$app->getSites()->getPrimarySite()->id;
		$translated = Plugin::getInstance()->aiTranslate->translateStrings($missing, $sourceSiteId, $targetSiteId);

		$this->save($formId, $targetSiteId, $existing + $translated);

		return count($translated);
	}
}
