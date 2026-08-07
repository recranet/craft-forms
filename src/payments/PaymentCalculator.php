<?php

namespace recranet\forms\payments;

use recranet\forms\models\Form;

/**
 * Computes what a submission owes, in whole cents, from the FORM DEFINITION —
 * never from anything the visitor posted. The visitor only contributes
 * choices (which option, how many); every price comes from the field rows an
 * editor configured, so a hostile client cannot pay €0.01 for a €500 option.
 *
 * Pricing model:
 * - Choice fields (select, radio, checkboxes) carry a `prices` list parallel
 *   to `options` (comma-separated, decimal point: "25, 40.50, 0"). A chosen
 *   option adds its price; checkboxes add every chosen option's price.
 * - A number field carries a single `price` per unit: value × price.
 * - The form's `paymentBase` is a flat amount added on top.
 *
 * Fields hidden by their conditions must be excluded by the caller (pass the
 * same formData that survived RuleEvaluator's discard cascade — a hidden
 * field's value is null there, so it naturally contributes nothing).
 *
 * Pure and static on purpose: this is the money math, it must be unit-testable
 * without Craft.
 */
final class PaymentCalculator
{
	/**
	 * Total in whole cents for the submitted values. 0 or negative results
	 * mean "nothing to pay" — the caller skips the payment step entirely.
	 *
	 * @param array<int, array> $fields Field definition rows (form or snapshot)
	 * @param array<string, mixed> $formDataByUid Values keyed by field uid
	 */
	public static function totalCents(Form $form, array $fields, array $formDataByUid): int
	{
		$total = self::parseAmountCents((string)$form->paymentBase);

		foreach ($fields as $field) {
			$uid = $field['uid'] ?? null;
			$type = $field['type'] ?? '';

			if (!$uid || !array_key_exists($uid, $formDataByUid)) {
				continue;
			}

			$value = $formDataByUid[$uid];

			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			if (in_array($type, ['select', 'radio', 'checkboxes'], true)) {
				$priceByOption = self::pricesByOption($field);

				if ($priceByOption === []) {
					continue;
				}

				$chosen = is_array($value) ? $value : [$value];

				foreach ($chosen as $option) {
					if (is_scalar($option) && isset($priceByOption[trim((string)$option)])) {
						$total += $priceByOption[trim((string)$option)];
					}
				}
			}

			if ($type === 'number' && is_numeric($value)) {
				$unitPrice = self::parseAmountCents((string)($field['price'] ?? ''));

				if ($unitPrice > 0) {
					// Quantities are whole units; a negative quantity cannot
					// turn into a discount
					$quantity = max(0, (int)$value);
					$total += $quantity * $unitPrice;
				}
			}
		}

		return $total;
	}

	/**
	 * Option => price-in-cents map for a choice field, aligning the `prices`
	 * list with the `options` list by position. Shorter price lists price
	 * only the first options; anything unparseable counts as no price.
	 *
	 * @return array<string, int>
	 */
	public static function pricesByOption(array $field): array
	{
		$prices = array_map('trim', explode(',', (string)($field['prices'] ?? '')));

		if (implode('', $prices) === '') {
			return [];
		}

		$options = array_values(array_filter(array_map('trim', explode(',', (string)($field['options'] ?? ''))), fn($o) => $o !== ''));
		$map = [];

		foreach ($options as $i => $option) {
			$cents = self::parseAmountCents($prices[$i] ?? '');

			if ($cents !== 0) {
				$map[$option] = $cents;
			}
		}

		return $map;
	}

	/**
	 * "25" → 2500, "40.50" → 4050, "12,50" → 1250 (a single comma is a
	 * decimal comma — editors will type it, so accept it in single amounts;
	 * the parallel `prices` LIST always uses the decimal point, its commas
	 * separate entries). Unparseable or empty → 0.
	 */
	public static function parseAmountCents(string $amount): int
	{
		$amount = trim($amount);

		if ($amount === '') {
			return 0;
		}

		// A decimal comma in a single amount (never in the prices list —
		// that's already split on commas by the time it gets here)
		$amount = str_replace(',', '.', $amount);

		if (!is_numeric($amount)) {
			return 0;
		}

		return (int)round((float)$amount * 100);
	}

	/**
	 * Cents formatted for Mollie's API: "12.50" with a decimal point, always
	 * two decimals.
	 */
	public static function formatForApi(int $cents): string
	{
		return number_format($cents / 100, 2, '.', '');
	}
}
