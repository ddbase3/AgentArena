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

use Base3\AgentArena\Migration\ArenaMatchMigrationProvider;
use Base3\AgentArena\Migration\ArenaMigration001CreateMatches;
use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Database\Api\IDatabase;
use PHPUnit\Framework\TestCase;

final class ArenaMigrationTest extends TestCase {

	public function testProviderActivatesOnlyWithConfiguredDatabase(): void {
		$container = $this->createMock(IContainer::class);
		$called = 0;
		$container->expects(self::exactly(2))->method('has')->with(IDatabase::class)
			->willReturnCallback(static function(string $name) use (&$called): bool {
				return $called++ === 1;
			});
		$provider = new ArenaMatchMigrationProvider($container);
		self::assertSame('arenamatchmigrationprovider', $provider::getName());
		self::assertFalse($provider->isActive());
		self::assertTrue($provider->isActive());
		self::assertSame([ArenaMigration001CreateMatches::class], $provider->getMigrations());
	}

	public function testImmutableMigrationCreatesOnlyOwnedTable(): void {
		$database = $this->createMock(IDatabase::class);
		$database->expects(self::once())->method('connect');
		$database->expects(self::once())->method('nonQuery')->willReturnCallback(static function(string $query): void {
			self::assertStringContainsString('CREATE TABLE IF NOT EXISTS agentarena_matches', $query);
			self::assertStringContainsString('PRIMARY KEY', $query);
			self::assertStringContainsString('INDEX agentarena_owner_idx (authority, owner_id, updated_at)', $query);
			self::assertStringContainsString('ENGINE=InnoDB', $query);
		});
		$migration = new ArenaMigration001CreateMatches($database);
		self::assertSame('arenamigration001creatematches', $migration::getName());
		self::assertSame('001', $migration->getVersion());
		self::assertSame('Create Agent Arena matches', $migration->getDescription());
		$migration->up();
	}
}
