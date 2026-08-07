<?php

namespace recranet\forms\tests\migrations;

use PHPUnit\Framework\TestCase;

/**
 * Fresh installs run ONLY Install.php — Craft marks the numbered migrations
 * as applied via schemaVersion. So every table a numbered migration creates
 * and every column it adds must also exist in Install.php, or new installs
 * silently miss schema that updated installs have. (This caught the
 * form-translations table missing from Install.php.)
 *
 * Textual check on purpose: it runs without a database and fails the build
 * the moment a migration introduces schema that Install.php doesn't carry.
 */
class InstallParityTest extends TestCase
{
	private static function migrationsDir(): string
	{
		return dirname(__DIR__, 2) . '/src/migrations';
	}

	private static function installSource(): string
	{
		return file_get_contents(self::migrationsDir() . '/Install.php');
	}

	/**
	 * @return string[] paths of the numbered (non-Install) migrations
	 */
	private static function numberedMigrations(): array
	{
		return glob(self::migrationsDir() . '/m*.php') ?: [];
	}

	public function testEveryMigrationTableExistsInInstall(): void
	{
		$install = self::installSource();
		$checked = 0;

		foreach (self::numberedMigrations() as $path) {
			$source = file_get_contents($path);
			preg_match_all("/createTable\\(\\s*'({{%[a-z_]+}})'/", $source, $matches);

			foreach ($matches[1] as $table) {
				$checked++;
				$this->assertStringContainsString(
					"createTable('{$table}'",
					$install,
					basename($path) . " creates {$table}, but Install.php does not — fresh installs will miss it",
				);
			}
		}

		$this->assertGreaterThan(0, $checked, 'expected at least one createTable in the numbered migrations');
	}

	public function testEveryMigrationColumnExistsInInstall(): void
	{
		$install = self::installSource();

		foreach (self::numberedMigrations() as $path) {
			$source = file_get_contents($path);
			preg_match_all("/addColumn\\(\\s*[^,]+,\\s*'([a-zA-Z]+)'/", $source, $matches);

			foreach ($matches[1] as $column) {
				$this->assertStringContainsString(
					"'{$column}'",
					$install,
					basename($path) . " adds column {$column}, but Install.php does not carry it — fresh installs will miss it",
				);
			}
		}
	}
}
