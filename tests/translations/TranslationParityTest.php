<?php

namespace recranet\forms\tests\translations;

use PHPUnit\Framework\TestCase;

/**
 * Every locale file must carry exactly the same keys: a string added to five
 * of the six files renders untranslated in the sixth, and nothing else flags
 * it. CLAUDE.md's "add to all six files" convention, enforced.
 */
class TranslationParityTest extends TestCase
{
	private const LOCALES = ['nl', 'en', 'de', 'fr', 'es', 'it'];

	/**
	 * @return array<string, array<string, string>> messages keyed by locale
	 */
	private static function load(): array
	{
		$messages = [];

		foreach (self::LOCALES as $locale) {
			$messages[$locale] = require dirname(__DIR__, 2) . "/src/translations/{$locale}/recranet-forms.php";
		}

		return $messages;
	}

	public function testEveryLocaleCarriesTheSameKeys(): void
	{
		$messages = self::load();
		$reference = array_keys($messages['nl']);
		sort($reference);

		foreach (self::LOCALES as $locale) {
			$keys = array_keys($messages[$locale]);
			sort($keys);

			$missing = array_diff($reference, $keys);
			$extra = array_diff($keys, $reference);

			$this->assertSame([], array_values($missing), "{$locale} is missing keys that nl has");
			$this->assertSame([], array_values($extra), "{$locale} has keys that nl lacks");
		}
	}

	public function testNoEmptyTranslations(): void
	{
		foreach (self::load() as $locale => $messages) {
			foreach ($messages as $key => $value) {
				$this->assertIsString($value, "{$locale}: \"{$key}\" is not a string");
				$this->assertNotSame('', trim($value), "{$locale}: \"{$key}\" is empty");
			}
		}
	}

	/**
	 * Placeholders like {label} must survive translation — a dropped one
	 * renders the raw brace text to visitors.
	 */
	public function testPlaceholdersSurviveTranslation(): void
	{
		$messages = self::load();

		foreach ($messages['nl'] as $key => $unused) {
			preg_match_all('/\{[a-zA-Z]+\}/', $key, $matches);

			foreach ($matches[0] as $placeholder) {
				foreach (self::LOCALES as $locale) {
					$this->assertStringContainsString(
						$placeholder,
						$messages[$locale][$key] ?? '',
						"{$locale}: \"{$key}\" lost its {$placeholder} placeholder",
					);
				}
			}
		}
	}
}
