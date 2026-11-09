<?php declare(strict_types=1);

namespace AgentArena\Test\Migration;

use AgentArena\Migration\ArenaMatchMigrationProvider;
use AgentArena\Migration\ArenaMigration001CreateMatches;
use Base3\Api\IContainer;
use Base3\Database\Api\IDatabase;
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
