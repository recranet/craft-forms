<?php

namespace recranet\forms\rules;

/**
 * Evaluates a field's conditional-visibility rules against submitted data.
 *
 * The rule shape (stored on a field row under the optional `conditions` key):
 *
 *   ['mode' => 'all'|'any', 'rules' => [
 *       ['field' => '<uid of another field>', 'operator' => 'is'|'isNot'|'contains'|'isEmpty'|'isNotEmpty', 'value' => '<string>'],
 *   ]]
 *
 * This is the server half of the evaluator; the JS half lives inline in
 * src/templates/_render/form.twig and MUST stay in parity with the
 * semantics here:
 *
 * - Values are compared as strings, trimmed and case-insensitive.
 * - Checkbox/consent values are booleans in formData: true normalizes to
 *   '1', false to '' — so builder rules compare against '1' (checked) or
 *   empty (unchecked).
 * - Multi-value fields (checkboxes) are arrays: `is`/`contains` pass when
 *   ANY selected option matches, `isNot` passes only when NO option
 *   matches, `isEmpty`/`isNotEmpty` look at whether anything is selected.
 * - null / missing values normalize to ''.
 * - `contains` with an empty comparison value always matches (mirrors
 *   PHP str_contains / JS String.includes with an empty needle).
 * - Fail-open on anything malformed: a rule with an unknown operator,
 *   a missing/unknown field reference, or a broken shape evaluates as
 *   passing, and absent/empty conditions mean always visible. A broken
 *   rule must never hide a field and lock a visitor out of submitting.
 */
class RuleEvaluator
{
	/** Operators the evaluator understands (also used for builder-side shape checks) */
	public const OPERATORS = ['is', 'isNot', 'contains', 'isEmpty', 'isNotEmpty'];

	/**
	 * Whether a field with the given conditions is visible for the given
	 * uid-keyed form data. `mode` 'all' requires every rule to pass, 'any'
	 * requires at least one; anything else is treated as 'all'.
	 *
	 * @param array|null $conditions The field's `conditions` value
	 * @param array<string, mixed> $formDataByUid Submitted values keyed by field uid
	 */
	public static function isVisible(?array $conditions, array $formDataByUid): bool
	{
		$rules = $conditions['rules'] ?? null;

		// No (usable) conditions = always visible
		if (!is_array($rules) || $rules === []) {
			return true;
		}

		$any = ($conditions['mode'] ?? null) === 'any';

		foreach ($rules as $rule) {
			$passes = self::rulePasses($rule, $formDataByUid);

			// Short-circuit: 'any' is satisfied by the first pass,
			// 'all' is broken by the first failure
			if ($any && $passes) {
				return true;
			}

			if (!$any && !$passes) {
				return false;
			}
		}

		// 'all': every rule passed; 'any': none did
		return !$any;
	}

	/**
	 * The set of field uids hidden by their conditions, cascaded until
	 * stable: a hidden field's value counts as empty for rules on other
	 * fields, so chains (B depends on A, C depends on B) resolve the same
	 * as the front-end JS, where hidden inputs are disabled and post
	 * nothing. Once a field is hidden during the cascade it stays hidden
	 * for this evaluation — its value is discarded, never resurrected.
	 * Values only ever move toward empty, so the loop terminates in at
	 * most count($fields) passes.
	 *
	 * @param array<int, array> $fields Field definition rows (form fields or a submission snapshot)
	 * @param array<string, mixed> $formDataByUid Submitted values keyed by field uid
	 * @return string[] Uids of hidden fields
	 */
	public static function hiddenFieldUids(array $fields, array $formDataByUid): array
	{
		$data = $formDataByUid;
		$hidden = [];
		$maxPasses = count($fields);

		do {
			$changed = false;

			foreach ($fields as $field) {
				$uid = $field['uid'] ?? null;

				if (!$uid || isset($hidden[$uid])) {
					continue;
				}

				$conditions = $field['conditions'] ?? null;

				if (!is_array($conditions)) {
					continue;
				}

				if (!self::isVisible($conditions, $data)) {
					$hidden[$uid] = true;
					// Hidden values count as empty for dependent rules
					$data[$uid] = null;
					$changed = true;
				}
			}
		} while ($changed && --$maxPasses > 0);

		return array_keys($hidden);
	}

	/**
	 * Whether a single rule passes. Malformed rules (missing keys, unknown
	 * operator, reference to a field that isn't in the data at all) pass —
	 * fail-open so a broken rule can never hide a field.
	 *
	 * @param mixed $rule One rule row
	 * @param array<string, mixed> $data Values keyed by field uid
	 */
	private static function rulePasses(mixed $rule, array $data): bool
	{
		if (!is_array($rule) || empty($rule['field']) || empty($rule['operator'])) {
			return true;
		}

		// A reference to an unknown/deleted field can never hide anything.
		// (Hidden fields keep their key with a null value, so they still
		// compare as empty rather than hitting this branch.)
		if (!array_key_exists($rule['field'], $data)) {
			return true;
		}

		$raw = $data[$rule['field']];
		$expected = self::normalize($rule['value'] ?? '');

		// Multi-value fields (checkboxes): a rule matches when ANY selected
		// option satisfies it. Kept in strict parity with the array branch of
		// rulePasses() in the form.twig JS.
		if (is_array($raw)) {
			$values = array_values(array_filter(
				array_map([self::class, 'normalize'], $raw),
				fn(string $value) => $value !== '',
			));

			return match ($rule['operator']) {
				'is' => in_array($expected, $values, true),
				'isNot' => !in_array($expected, $values, true),
				'contains' => array_filter($values, fn(string $value) => str_contains($value, $expected)) !== [],
				'isEmpty' => $values === [],
				'isNotEmpty' => $values !== [],
				// Unknown operator: fail open, never hide
				default => true,
			};
		}

		$actual = self::normalize($raw);

		return match ($rule['operator']) {
			'is' => $actual === $expected,
			'isNot' => $actual !== $expected,
			'contains' => str_contains($actual, $expected),
			'isEmpty' => $actual === '',
			'isNotEmpty' => $actual !== '',
			// Unknown operator: fail open, never hide
			default => true,
		};
	}

	/**
	 * Normalize a value for comparison: booleans become '1'/'' (checkbox
	 * semantics), null/non-scalars become '', strings are trimmed and
	 * lowercased. Mirrors normalize() in the front-end JS.
	 */
	private static function normalize(mixed $value): string
	{
		if (is_bool($value)) {
			return $value ? '1' : '';
		}

		if ($value === null || !is_scalar($value)) {
			return '';
		}

		return mb_strtolower(trim((string)$value));
	}
}
