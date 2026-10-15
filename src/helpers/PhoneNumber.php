<?php

namespace recranet\forms\helpers;

/**
 * What a phone field accepts: digits with the separators people actually
 * type (spaces, hyphens, parentheses) and one optional leading "+" for an
 * international prefix — "06-12345678", "+31 6 1234 5678", "(010) 123 45 67".
 * No letters, so "bel me maar" or "zie e-mail" can't land in the field.
 *
 * Deliberately not a per-country check: the forms take enquiries from abroad,
 * and a strict NL format would turn away a German or Belgian number. 6 digits
 * is the shortest real number, 15 the E.164 maximum.
 *
 * PATTERN is the browser half (the `pattern` attribute in
 * _render/form.twig); keep the character set of the two in step.
 */
final class PhoneNumber
{
	/** HTML `pattern` value — escaped for the browser's strict (v-flag) regex mode */
	public const PATTERN = '\+?[0-9 \-\(\)]{6,20}';

	public static function isValid(string $value): bool
	{
		$value = trim($value);

		if (!preg_match('/^\+?[0-9 \-()]+$/', $value)) {
			return false;
		}

		$digits = strlen(preg_replace('/\D/', '', $value));

		return $digits >= 6 && $digits <= 15;
	}
}
