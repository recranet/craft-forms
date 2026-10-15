<?php

namespace recranet\forms\tests\helpers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use recranet\forms\helpers\PhoneNumber;

/**
 * The server half of the phone check. The browser half is PhoneNumber::PATTERN
 * in the form template — a value accepted here should pass there too.
 */
class PhoneNumberTest extends TestCase
{
	public static function validNumbers(): array
	{
		return [
			'mobile' => ['0612345678'],
			'international with spaces' => ['+31 6 1234 5678'],
			'hyphen' => ['06-12345678'],
			'area code in parentheses' => ['(010) 123 45 67'],
			'00 prefix' => ['0031612345678'],
			'foreign' => ['+49 30 1234567'],
			'surrounding whitespace' => ['  0612345678 '],
		];
	}

	public static function invalidNumbers(): array
	{
		return [
			'text' => ['bel me maar'],
			'letters mixed in' => ['06abc12345'],
			'prefix only' => ['+31'],
			'too short' => ['12345'],
			'too long' => ['+31 6 1234 5678 90123'],
			'double plus' => ['++31612345678'],
			'plus in the middle' => ['06+12345678'],
			'separators only' => ['------'],
			'email' => ['jan@example.com'],
		];
	}

	#[DataProvider('validNumbers')]
	public function testAccepts(string $value): void
	{
		$this->assertTrue(PhoneNumber::isValid($value));
	}

	#[DataProvider('invalidNumbers')]
	public function testRejects(string $value): void
	{
		$this->assertFalse(PhoneNumber::isValid($value));
	}
}
