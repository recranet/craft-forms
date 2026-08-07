<?php

namespace recranet\forms\tests\rules;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use recranet\forms\rules\RuleEvaluator;

/**
 * The evaluator's semantics are a contract shared with the inline JS in
 * src/templates/_render/form.twig — every behavior asserted here must hold
 * there too. When a test changes, check the JS half.
 */
class RuleEvaluatorTest extends TestCase
{
	private static function rule(string $field, string $operator, string $value = ''): array
	{
		return ['field' => $field, 'operator' => $operator, 'value' => $value];
	}

	private static function conditions(array $rules, string $mode = 'all'): array
	{
		return ['mode' => $mode, 'rules' => $rules];
	}

	// --- isVisible: basics -------------------------------------------------

	public function testNoConditionsMeansVisible(): void
	{
		$this->assertTrue(RuleEvaluator::isVisible(null, []));
		$this->assertTrue(RuleEvaluator::isVisible([], []));
		$this->assertTrue(RuleEvaluator::isVisible(['mode' => 'all', 'rules' => []], []));
		$this->assertTrue(RuleEvaluator::isVisible(['rules' => 'not-an-array'], []));
	}

	#[DataProvider('scalarOperatorCases')]
	public function testScalarOperators(string $operator, string $ruleValue, mixed $actual, bool $expected): void
	{
		$conditions = self::conditions([self::rule('a', $operator, $ruleValue)]);

		$this->assertSame($expected, RuleEvaluator::isVisible($conditions, ['a' => $actual]));
	}

	public static function scalarOperatorCases(): array
	{
		return [
			'is match' => ['is', 'yes', 'yes', true],
			'is mismatch' => ['is', 'yes', 'no', false],
			// Comparison is trimmed + case-insensitive on both sides
			'is trimmed/case-insensitive' => ['is', '  YES ', 'yes', true],
			'isNot mismatch passes' => ['isNot', 'yes', 'no', true],
			'isNot match fails' => ['isNot', 'yes', 'yes', false],
			'contains substring' => ['contains', 'oo', 'foobar boo', true],
			'contains missing' => ['contains', 'xyz', 'foobar', false],
			// Empty needle always matches (str_contains / String.includes semantics)
			'contains empty needle' => ['contains', '', 'anything', true],
			'isEmpty on empty string' => ['isEmpty', '', '', true],
			'isEmpty on null' => ['isEmpty', '', null, true],
			'isEmpty on value' => ['isEmpty', '', 'x', false],
			'isNotEmpty on value' => ['isNotEmpty', '', 'x', true],
			'isNotEmpty on null' => ['isNotEmpty', '', null, false],
			// Checkbox/consent booleans normalize to '1' / ''
			'checked checkbox is 1' => ['is', '1', true, true],
			'unchecked checkbox isEmpty' => ['isEmpty', '', false, true],
			'unchecked checkbox is 1 fails' => ['is', '1', false, false],
			// Arrays take the multi-value branch, even with string keys
			'keyed array takes multi-value branch' => ['isEmpty', '', ['nested' => 'still-a-selection'], false],
		];
	}

	// --- isVisible: multi-value (checkboxes) --------------------------------

	public function testArrayValueMatchesWhenAnyOptionSatisfies(): void
	{
		$data = ['a' => ['Red', 'Green']];

		$this->assertTrue(RuleEvaluator::isVisible(self::conditions([self::rule('a', 'is', 'green')]), $data));
		$this->assertFalse(RuleEvaluator::isVisible(self::conditions([self::rule('a', 'is', 'blue')]), $data));
		$this->assertTrue(RuleEvaluator::isVisible(self::conditions([self::rule('a', 'contains', 'ree')]), $data));
	}

	public function testArrayIsNotPassesOnlyWhenNoOptionMatches(): void
	{
		$data = ['a' => ['Red', 'Green']];

		$this->assertFalse(RuleEvaluator::isVisible(self::conditions([self::rule('a', 'isNot', 'red')]), $data));
		$this->assertTrue(RuleEvaluator::isVisible(self::conditions([self::rule('a', 'isNot', 'blue')]), $data));
	}

	public function testArrayEmptiness(): void
	{
		$this->assertTrue(RuleEvaluator::isVisible(self::conditions([self::rule('a', 'isEmpty')]), ['a' => []]));
		// Options that normalize to '' don't count as a selection
		$this->assertTrue(RuleEvaluator::isVisible(self::conditions([self::rule('a', 'isEmpty')]), ['a' => ['', '  ']]));
		$this->assertTrue(RuleEvaluator::isVisible(self::conditions([self::rule('a', 'isNotEmpty')]), ['a' => ['x']]));
	}

	// --- isVisible: all/any ------------------------------------------------

	public function testAllModeRequiresEveryRule(): void
	{
		$conditions = self::conditions([self::rule('a', 'is', 'x'), self::rule('b', 'is', 'y')], 'all');

		$this->assertTrue(RuleEvaluator::isVisible($conditions, ['a' => 'x', 'b' => 'y']));
		$this->assertFalse(RuleEvaluator::isVisible($conditions, ['a' => 'x', 'b' => 'nope']));
	}

	public function testAnyModeRequiresOneRule(): void
	{
		$conditions = self::conditions([self::rule('a', 'is', 'x'), self::rule('b', 'is', 'y')], 'any');

		$this->assertTrue(RuleEvaluator::isVisible($conditions, ['a' => 'nope', 'b' => 'y']));
		$this->assertFalse(RuleEvaluator::isVisible($conditions, ['a' => 'nope', 'b' => 'nope']));
	}

	public function testUnknownModeIsTreatedAsAll(): void
	{
		$conditions = self::conditions([self::rule('a', 'is', 'x'), self::rule('b', 'is', 'y')], 'bogus');

		$this->assertFalse(RuleEvaluator::isVisible($conditions, ['a' => 'x', 'b' => 'nope']));
	}

	// --- isVisible: fail-open ------------------------------------------------

	public function testMalformedRulesFailOpen(): void
	{
		$data = ['a' => 'whatever'];

		// Unknown operator, missing keys, non-array rule, unknown field ref:
		// each must evaluate as passing — a broken rule may never hide a field
		$this->assertTrue(RuleEvaluator::isVisible(self::conditions([self::rule('a', 'greaterThan', '5')]), $data));
		$this->assertTrue(RuleEvaluator::isVisible(self::conditions([['value' => 'x']]), $data));
		$this->assertTrue(RuleEvaluator::isVisible(self::conditions(['not-a-rule']), $data));
		$this->assertTrue(RuleEvaluator::isVisible(self::conditions([self::rule('ghost-uid', 'is', 'x')]), $data));
	}

	public function testUnknownOperatorOnArrayValueFailsOpen(): void
	{
		$this->assertTrue(RuleEvaluator::isVisible(
			self::conditions([self::rule('a', 'greaterThan', '5')]),
			['a' => ['x']],
		));
	}

	// --- hiddenFieldUids -----------------------------------------------------

	public function testHiddenFieldUids(): void
	{
		$fields = [
			['uid' => 'a', 'type' => 'select'],
			['uid' => 'b', 'type' => 'text', 'conditions' => self::conditions([self::rule('a', 'is', 'other')])],
			['uid' => 'c', 'type' => 'text'],
		];

		$this->assertSame(['b'], RuleEvaluator::hiddenFieldUids($fields, ['a' => 'phone', 'b' => 'junk', 'c' => 'kept']));
		$this->assertSame([], RuleEvaluator::hiddenFieldUids($fields, ['a' => 'other', 'b' => 'kept', 'c' => 'kept']));
	}

	/**
	 * Chained conditions cascade: hiding B empties B's value, which in turn
	 * hides C (C shows only while B has a value). Mirrors the front end,
	 * where a hidden field's inputs are disabled and post nothing.
	 */
	public function testHiddenFieldUidsCascadesChains(): void
	{
		$fields = [
			['uid' => 'a', 'type' => 'checkbox'],
			['uid' => 'b', 'type' => 'text', 'conditions' => self::conditions([self::rule('a', 'is', '1')])],
			['uid' => 'c', 'type' => 'text', 'conditions' => self::conditions([self::rule('b', 'isNotEmpty')])],
		];
		$data = ['a' => false, 'b' => 'smuggled', 'c' => 'smuggled'];

		$this->assertSame(['b', 'c'], RuleEvaluator::hiddenFieldUids($fields, $data));
	}

	public function testHiddenFieldStaysHiddenOncePruned(): void
	{
		// b hides when a is empty; c shows only while b is empty. Hiding b
		// empties b, which would show c — but b itself must stay hidden.
		$fields = [
			['uid' => 'a', 'type' => 'text'],
			['uid' => 'b', 'type' => 'text', 'conditions' => self::conditions([self::rule('a', 'isNotEmpty')])],
			['uid' => 'c', 'type' => 'text', 'conditions' => self::conditions([self::rule('b', 'isEmpty')])],
		];

		$this->assertSame(['b'], RuleEvaluator::hiddenFieldUids($fields, ['a' => '', 'b' => 'x', 'c' => 'y']));
	}

	public function testFieldsWithoutUidOrConditionsAreIgnored(): void
	{
		$fields = [
			['type' => 'heading'],
			['uid' => 'a', 'type' => 'text', 'conditions' => 'malformed-string'],
		];

		$this->assertSame([], RuleEvaluator::hiddenFieldUids($fields, ['a' => '']));
	}
}
