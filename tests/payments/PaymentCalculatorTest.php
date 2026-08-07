<?php

namespace recranet\forms\tests\payments;

use PHPUnit\Framework\TestCase;
use recranet\forms\models\Form;
use recranet\forms\payments\PaymentCalculator;

/**
 * The money math. Everything here is what a submission OWES — computed from
 * the form definition, never from visitor-posted amounts.
 */
class PaymentCalculatorTest extends TestCase
{
	private static function form(string $base = ''): Form
	{
		$form = new Form();
		$form->paymentBase = $base;

		return $form;
	}

	public function testParseAmounts(): void
	{
		$this->assertSame(2500, PaymentCalculator::parseAmountCents('25'));
		$this->assertSame(4050, PaymentCalculator::parseAmountCents('40.50'));
		// Editors type decimal commas in single amounts
		$this->assertSame(1250, PaymentCalculator::parseAmountCents('12,50'));
		$this->assertSame(0, PaymentCalculator::parseAmountCents(''));
		$this->assertSame(0, PaymentCalculator::parseAmountCents('gratis'));
		// Rounding, not truncation
		$this->assertSame(1000, PaymentCalculator::parseAmountCents('9.999'));
	}

	public function testFormatForApi(): void
	{
		$this->assertSame('12.50', PaymentCalculator::formatForApi(1250));
		$this->assertSame('0.05', PaymentCalculator::formatForApi(5));
		$this->assertSame('100.00', PaymentCalculator::formatForApi(10000));
	}

	public function testChoicePricesAlignWithOptions(): void
	{
		$field = ['options' => 'Basic, Luxe, Free', 'prices' => '25, 40.50, 0'];

		$this->assertSame(['Basic' => 2500, 'Luxe' => 4050], PaymentCalculator::pricesByOption($field));
		// No prices configured = the field doesn't price anything
		$this->assertSame([], PaymentCalculator::pricesByOption(['options' => 'A, B', 'prices' => '']));
	}

	public function testTotalSumsBaseOptionsAndQuantities(): void
	{
		$fields = [
			['uid' => 'pkg', 'type' => 'radio', 'options' => 'Basic, Luxe', 'prices' => '25, 40'],
			['uid' => 'extras', 'type' => 'checkboxes', 'options' => 'Wifi, Ontbijt', 'prices' => '5, 7.50'],
			['uid' => 'people', 'type' => 'number', 'price' => '10'],
			['uid' => 'name', 'type' => 'text'],
		];
		$data = [
			'pkg' => 'Luxe',
			'extras' => ['Wifi', 'Ontbijt'],
			'people' => '3',
			'name' => 'Jan',
		];

		// 12.50 base + 40 + 5 + 7.50 + 3×10 = 95.00
		$this->assertSame(9500, PaymentCalculator::totalCents(self::form('12.50'), $fields, $data));
	}

	public function testVisitorInputCannotChangePrices(): void
	{
		$fields = [['uid' => 'pkg', 'type' => 'radio', 'options' => 'Luxe', 'prices' => '500']];

		// Unknown option (hostile post) prices nothing; validation rejects it separately
		$this->assertSame(0, PaymentCalculator::totalCents(self::form(), $fields, ['pkg' => 'gratis-optie']));
		// Negative quantities can't become discounts
		$this->assertSame(
			0,
			PaymentCalculator::totalCents(self::form(), [['uid' => 'n', 'type' => 'number', 'price' => '10']], ['n' => '-5']),
		);
	}

	public function testHiddenFieldsContributeNothing(): void
	{
		// A condition-hidden field's value is null in formData (the discard
		// cascade) — its price must not count
		$fields = [['uid' => 'pkg', 'type' => 'radio', 'options' => 'Luxe', 'prices' => '500']];

		$this->assertSame(0, PaymentCalculator::totalCents(self::form(), $fields, ['pkg' => null]));
	}

	public function testFreeChoicesMakeNoPayment(): void
	{
		$fields = [['uid' => 'pkg', 'type' => 'radio', 'options' => 'Free, Paid', 'prices' => '0, 25']];

		$this->assertSame(0, PaymentCalculator::totalCents(self::form(), $fields, ['pkg' => 'Free']));
	}
}
