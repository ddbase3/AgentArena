<?php declare(strict_types=1);

namespace AgentArena\Test\Repository;

use AgentArena\Game\ArenaRules;
use AgentArena\Repository\ArenaMatchRepository;
use Base3\Database\Api\IDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ArenaMatchRepositoryTest extends TestCase {

	private const ID = '0123456789abcdef0123456789abcdef';
	private const ACTOR = ['authority' => 'host', 'id' => '17'];

	public function testCreateEscapesOwnerAndSerializesAuthoritativeState(): void {
		$database = $this->database();
		$state = ArenaRules::create(self::ID);
		$captured = '';
		$database->expects(self::once())->method('connect');
		$database->expects(self::once())->method('nonQuery')->willReturnCallback(
			static function(string $query) use (&$captured): void { $captured = $query; }
		);
		(new ArenaMatchRepository($database))->create(['authority' => "host'X", 'id' => '17'], $state);
		self::assertStringContainsString('INSERT INTO agentarena_matches', $captured);
		self::assertStringContainsString("'host\\'X'", $captured);
		self::assertStringContainsString("'17'", $captured);
		self::assertStringContainsString('match_started', $captured);
	}

	public function testLatestOwnedReturnsNullWhenNoMatchExists(): void {
		$database = $this->database();
		$database->method('singleQuery')->willReturn(null);
		self::assertNull((new ArenaMatchRepository($database))->latestOwned(self::ACTOR));
	}

	public function testLatestOwnedLoadsExactOwnerMatch(): void {
		$state = ArenaRules::create(self::ID);
		$database = $this->database();
		$database->method('singleQuery')->willReturnCallback(static function(string $query) use ($state): array {
			if (str_starts_with($query, 'SELECT id FROM')) { return ['id' => self::ID]; }
			return ['revision' => 4, 'payload' => json_encode($state, JSON_THROW_ON_ERROR)];
		});
		$found = (new ArenaMatchRepository($database))->latestOwned(self::ACTOR);
		self::assertSame(self::ID, $found['id']);
		self::assertSame(4, $found['revision']);
	}

	public function testListOwnedUsesBoundedOwnerQueryAndOnlyAuthoritativeMetadata(): void {
		$state = ArenaRules::create(self::ID, 'bastion');
		$database = $this->database();
		$database->expects(self::once())->method('multiQuery')->with(self::callback(
			static function(string $query): bool {
				self::assertStringContainsString("authority = 'host'", $query);
				self::assertStringContainsString("owner_id = '17'", $query);
				self::assertStringContainsString('LIMIT 20', $query);
				return true;
			}
		))->willReturn([[
			'id' => self::ID, 'payload' => json_encode($state, JSON_THROW_ON_ERROR),
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00'
		]]);
		$matches = (new ArenaMatchRepository($database))->listOwned(self::ACTOR);
		self::assertCount(1, $matches);
		self::assertSame('bastion', $matches[0]['map_id']);
		self::assertSame('human', $matches[0]['phase']);
		self::assertSame('2026-01-02 00:00:00', $matches[0]['updated_at']);
		self::assertArrayNotHasKey('units', $matches[0]);
	}

	public function testListOwnedRejectsForgedMatchIdentifier(): void {
		$state = ArenaRules::create(self::ID);
		$database = $this->database();
		$database->method('multiQuery')->willReturn([[
			'id' => str_repeat('f', 32), 'payload' => json_encode($state, JSON_THROW_ON_ERROR),
			'created_at' => '', 'updated_at' => ''
		]]);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.invalid_state');
		(new ArenaMatchRepository($database))->listOwned(self::ACTOR);
	}

	public function testLoadRejectsInvalidIdBeforeDatabaseQuery(): void {
		$database = $this->database();
		$database->expects(self::never())->method('singleQuery');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.match_not_found');
		(new ArenaMatchRepository($database))->loadOwned('not-a-match', self::ACTOR);
	}

	public function testLoadRejectsMissingOrWrongOwner(): void {
		$database = $this->database();
		$database->method('singleQuery')->willReturn(null);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.match_not_found');
		(new ArenaMatchRepository($database))->loadOwned(self::ID, self::ACTOR);
	}

	public function testLoadRejectsTamperedPayloadIdentity(): void {
		$database = $this->database();
		$database->method('singleQuery')->willReturn([
			'revision' => 2,
			'payload' => json_encode(ArenaRules::create(str_repeat('f', 32)), JSON_THROW_ON_ERROR)
		]);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.invalid_state');
		(new ArenaMatchRepository($database))->loadOwned(self::ID, self::ACTOR);
	}

	public function testLoadOverridesPayloadRevisionWithDatabaseRevision(): void {
		$state = ArenaRules::create(self::ID);
		$state['revision'] = 88;
		$database = $this->database();
		$database->expects(self::once())->method('singleQuery')->willReturn([
			'revision' => '3', 'payload' => json_encode($state, JSON_THROW_ON_ERROR)
		]);
		self::assertSame(3, (new ArenaMatchRepository($database))->loadOwned(self::ID, self::ACTOR)['revision']);
	}

	public function testReplaceUsesCompareAndSwapAndIncrementsRevision(): void {
		$database = $this->database();
		$state = ArenaRules::create(self::ID);
		$captured = '';
		$database->expects(self::once())->method('nonQuery')->willReturnCallback(
			static function(string $query) use (&$captured): void { $captured = $query; }
		);
		$database->method('affectedRows')->willReturn(1);
		(new ArenaMatchRepository($database))->replace(self::ACTOR, $state, 3);
		self::assertStringContainsString('revision = 4', $captured);
		self::assertStringContainsString('AND revision = 3', $captured);
		self::assertStringContainsString("authority = 'host'", $captured);
		self::assertStringContainsString("owner_id = '17'", $captured);
	}

	public function testReplaceRejectsStaleConcurrentCommit(): void {
		$database = $this->database();
		$database->method('affectedRows')->willReturn(0);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.stale_state');
		(new ArenaMatchRepository($database))->replace(self::ACTOR, ArenaRules::create(self::ID), 0);
	}

	public function testReplaceRejectsInvalidIdBeforeWrite(): void {
		$database = $this->database();
		$database->expects(self::never())->method('nonQuery');
		$state = ArenaRules::create('invalid');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.match_not_found');
		(new ArenaMatchRepository($database))->replace(self::ACTOR, $state, 0);
	}

	public function testDeleteAllOwnedIncludesBothIdentityParts(): void {
		$database = $this->database();
		$database->expects(self::once())->method('nonQuery')->willReturnCallback(static function(string $query): void {
			self::assertStringContainsString('DELETE FROM agentarena_matches', $query);
			self::assertStringContainsString("authority = 'host'", $query);
			self::assertStringContainsString("owner_id = '17'", $query);
		});
		(new ArenaMatchRepository($database))->deleteAllOwned(self::ACTOR);
	}

	private function database(): IDatabase {
		$database = $this->createMock(IDatabase::class);
		$database->method('escape')->willReturnCallback(static fn(string $value): string => addslashes($value));
		return $database;
	}
}
