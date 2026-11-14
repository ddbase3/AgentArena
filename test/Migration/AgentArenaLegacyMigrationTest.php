<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of AgentArena for BASE3 Framework.
 *
 * AgentArena implements a tactical LLM-versus-human game for BASE3.
 * It provides authoritative rules, match storage and game displays.
 * MissionBay integrates the agent through the existing tool contracts.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0-or-later
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de
 ***********************************************************************/


namespace Base3\AgentArena\Test\Migration;

use Base3\CoreFoundation\Migration\Api\IDatabaseMigration;
use Base3\CoreFoundation\Migration\Api\ILegacyDatabaseMigration;
use Base3\CoreFoundation\Migration\Exception\MigrationException;
use Base3\AgentArena\Migration\ArenaMigration001CreateMatches;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentArenaLegacyMigrationTest extends TestCase {

	/**
	 * @return iterable<string, array{class-string<IDatabaseMigration>}>
	 */
	public static function historicalMigrations(): iterable {
		yield 'arenamigration001creatematches' => [ArenaMigration001CreateMatches::class];
	}

	#[DataProvider('historicalMigrations')]
	public function testHistoricalMigrationIsChecksumExemptAndRejectsDown(string $migrationClass): void {
		self::assertTrue(is_subclass_of($migrationClass, IDatabaseMigration::class));
		self::assertTrue(is_subclass_of($migrationClass, ILegacyDatabaseMigration::class));

		// The reverse operation must fail before any constructor dependency is needed.
		$migration = (new ReflectionClass($migrationClass))->newInstanceWithoutConstructor();
		$this->expectException(MigrationException::class);
		$this->expectExceptionMessage('Not implemented');
		$migration->down();
	}
}
