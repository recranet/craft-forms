<?php

namespace recranet\forms\services;

use Craft;
use recranet\aitranslator\AiTranslator;
use recranet\aitranslator\models\TranslationItem;
use recranet\aitranslator\providers\TranslationProviderInterface;
use yii\base\Component;

/**
 * Machine-translates form content between sites through the in-house
 * `recranet/craft-ai-translator` plugin.
 *
 * That plugin is an optional dependency, not a composer requirement: plenty of
 * projects are single-site and never install it. Everything here therefore
 * degrades to "not available" instead of fatalling — callers ask isAvailable()
 * first and hide the UI when it returns false.
 *
 * We deliberately reuse ai-translator's *provider* rather than reimplementing
 * an API client: the provider comes preconfigured with the project's glossary
 * (brand and place names that must stay untranslated) and its tone-of-voice
 * instructions, so form strings read the same as translated entry content.
 *
 * This service only turns strings into translated strings. Deciding which
 * strings are translatable, and storing the result, lives elsewhere.
 */
class AiTranslate extends Component
{
	/**
	 * Handle of the optional ai-translator plugin.
	 */
	private const AI_TRANSLATOR_HANDLE = 'ai-translator';


	/**
	 * Whether AI translation can actually run right now.
	 *
	 * Three separate things have to be true, and all three are normal reasons
	 * for a project to be without it: the plugin is installed, it is enabled,
	 * and it has a usable provider configuration (an API key). A missing key
	 * makes getProvider() throw — that is a "no" here, not an error to report.
	 */
	public function isAvailable(): bool
	{
		return $this->getProvider() !== null;
	}

	/**
	 * Translates a set of source strings from one site's language to another's.
	 *
	 * @param array<string, string> $strings source strings keyed by an arbitrary
	 *                                       identifier, e.g. ['field.42.label' => 'Naam']
	 * @param int $sourceSiteId site whose language the strings are written in
	 * @param int $targetSiteId site whose language they should end up in
	 * @return array<string, string> the translations under the same keys; keys
	 *                               whose source value was blank are omitted
	 * @throws \RuntimeException when translation cannot run or the provider
	 *                           returns a result we cannot safely map back
	 * @throws \recranet\aitranslator\errors\TranslationException on API failure —
	 *                                                           deliberately not caught, the caller decides how to report it
	 */
	public function translateStrings(array $strings, int $sourceSiteId, int $targetSiteId): array
	{
		$provider = $this->getProvider();

		if ($provider === null) {
			throw new \RuntimeException(Craft::t('recranet-forms', 'AI translation is not available. Install and configure the AI Translator plugin.'));
		}

		$sourceLanguage = $this->languageOf($sourceSiteId);
		$targetLanguage = $this->languageOf($targetSiteId);

		// Blank values are dropped rather than sent along: an empty string
		// costs an API slot, comes back as noise, and an absent key is what
		// the storage layer wants anyway (nothing to save).
		$keys = [];
		$items = [];

		foreach ($strings as $key => $value) {
			if (trim((string)$value) === '') {
				continue;
			}

			// Everything is sent as plain text: form content is labels,
			// placeholders and short bodies, and the default email templates
			// escape their output (nl2br), so HTML in a translation would
			// render literally anyway. Telling the model it is translating
			// HTML only invites it to "helpfully" add markup.
			$keys[] = $key;
			$items[] = new TranslationItem((string)$value, TranslationItem::FORMAT_TEXT);
		}

		if ($items === []) {
			return [];
		}

		// One batch call for the whole form: the provider is a batch API, and
		// per-string calls would multiply latency and cost by the field count.
		$translations = $provider->translateBatch($items, $sourceLanguage, $targetLanguage);

		// Results are matched back purely by position, so a count mismatch
		// means we can no longer tell which translation belongs to which key.
		// Assigning them anyway would silently write a label into a button and
		// an editor might never notice — fail loudly instead.
		if (count($translations) !== count($items)) {
			throw new \RuntimeException(Craft::t('recranet-forms', 'The translation service returned {got} translations for {expected} strings, so they could not be matched up. Nothing was saved.', [
				'got' => count($translations),
				'expected' => count($items),
			]));
		}

		return array_combine($keys, array_map('strval', array_values($translations)));
	}

	/**
	 * Resolves ai-translator's configured provider, or null when AI translation
	 * is unavailable for any reason.
	 *
	 * class_exists() is checked before anything else so this file stays safe to
	 * load when ai-translator is not in the project's vendor directory at all.
	 */
	private function getProvider(): ?TranslationProviderInterface
	{
		if (!class_exists(AiTranslator::class)) {
			return null;
		}

		// getPlugin() only returns installed *and* enabled plugins.
		$plugin = Craft::$app->getPlugins()->getPlugin(self::AI_TRANSLATOR_HANDLE);

		if (!$plugin instanceof AiTranslator) {
			return null;
		}

		try {
			return $plugin->translations->getProvider();
		} catch (\Throwable $e) {
			// Missing API key or an unknown provider setting: not an error to
			// surface, just a reason the feature stays switched off.
			Craft::info('AI translation unavailable: ' . $e->getMessage(), 'recranet-forms');

			return null;
		}
	}

	/**
	 * BCP 47 language tag for a site, as the provider interface expects.
	 *
	 * Craft site languages are already BCP 47 ("nl", "en-GB"), and ai-translator
	 * passes $site->language straight through to its providers; LocaleHelper
	 * only maps tags onto DeepL's own codes and has nothing to convert here.
	 * The underscore normalisation guards against locale IDs stored as "nl_NL".
	 */
	private function languageOf(int $siteId): string
	{
		$site = Craft::$app->getSites()->getSiteById($siteId);

		if (!$site) {
			throw new \RuntimeException("Cannot translate: site {$siteId} does not exist.");
		}

		return str_replace('_', '-', $site->language);
	}
}
