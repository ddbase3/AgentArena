<?php declare(strict_types=1);

namespace AgentArena\Test\Service;

use AgentArena\Game\ArenaRules;
use AgentArena\Repository\ArenaMatchRepository;
use AgentArena\Service\ArenaAccessService;
use AgentArena\Service\ArenaGameService;
use AgentArena\Test\Fixture\ArenaStoredMatch;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Database\Api\IDatabase;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\User;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ArenaGameServiceTest extends TestCase {

	private const ID = '0123456789abcdef0123456789abcdef';
	private const NONCE = '0123456789abcdef0123456789abcdef0123456789abcdef';

	public function testCreateAndResumeMatchUseExistingRepository(): void {
		[$game, $stored] = $this->scenario(ArenaRules::create(self::ID));
		$created = $game->createMatch('crossfire');
		self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $created['id']);
		self::assertSame('crossfire', $created['map_id']);
		self::assertSame(1, $stored->writes);
		self::assertStringContainsString('INSERT INTO agentarena_matches', end($stored->queries));
		self::assertSame(self::ID, $game->latestMatch()['id']);
		$stored->exists = false;
		self::assertNull($game->latestMatch());
	}

	public function testSnapshotNeverExposesAiExecutionCredentials(): void {
		$state = $this->claimed();
		[$game] = $this->scenario($state);
		$snapshot = $game->snapshot(self::ID);
		self::assertSame('ai_running', $snapshot['phase']);
		self::assertArrayNotHasKey('ai_nonce', $snapshot);
		self::assertArrayNotHasKey('ai_started_at', $snapshot);
		self::assertSame(self::ID, $snapshot['id']);
	}

	public function testHumanMoveAndEndTurnPersistTwoExactRevisions(): void {
		[$game, $stored] = $this->scenario(ArenaRules::create(self::ID));
		$moved = $game->humanCommand(self::ID, 0, ['type' => 'move', 'unit' => 'h_scout', 'x' => 2, 'y' => 1]);
		self::assertSame(1, $moved['revision']);
		self::assertSame(2, $moved['units']['h_scout']['x']);
		self::assertSame(1, $stored->state['revision']);
		$ended = $game->endHumanTurn(self::ID, 1);
		self::assertSame('ai', $ended['phase']);
		self::assertSame(2, $ended['revision']);
		self::assertSame(2, $stored->writes);
	}

	public function testStaleHumanRevisionDoesNotWrite(): void {
		[$game, $stored] = $this->scenario(ArenaRules::create(self::ID));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.stale_state');
		try {
			$game->humanCommand(self::ID, 1, ['type' => 'guard', 'unit' => 'h_scout']);
		} finally {
			self::assertSame(0, $stored->writes);
		}
	}

	public function testInvalidHumanCommandDoesNotWrite(): void {
		[$game, $stored] = $this->scenario(ArenaRules::create(self::ID));
		$this->expectException(\InvalidArgumentException::class);
		try {
			$game->humanCommand(self::ID, 0, ['type' => 'move', 'unit' => 'h_scout', 'x' => 8, 'y' => 3]);
		} finally {
			self::assertSame(0, $stored->writes);
		}
	}

	public function testDatabaseRevisionConflictPropagatesWithoutOverwritingState(): void {
		[$game, $stored] = $this->scenario(ArenaRules::create(self::ID));
		$stored->conflict = true;
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.stale_state');
		try {
			$game->endHumanTurn(self::ID, 0);
		} finally {
			self::assertSame(0, $stored->state['revision']);
			self::assertSame('human', $stored->state['phase']);
		}
	}

	public function testClaimAndToolReadUseSameNonceWithoutSecretDisclosure(): void {
		$state = ArenaRules::completePhase(ArenaRules::create(self::ID), 'human');
		[$game, $stored] = $this->scenario($state);
		$nonce = $game->claimAi(self::ID);
		self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/D', $nonce);
		self::assertSame('ai_running', $stored->state['phase']);
		self::assertSame($nonce, $stored->state['ai_nonce']);
		$public = $game->toolSnapshot(self::ID, $nonce);
		self::assertArrayHasKey('tactical_briefing', $public);
		self::assertArrayNotHasKey('ai_nonce', $public);
		self::assertSame(1, $stored->writes);
	}

	public function testClaimRejectsFreshConcurrentAiTurn(): void {
		[$game, $stored] = $this->scenario($this->claimed());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.invalid_phase');
		try { $game->claimAi(self::ID); }
		finally { self::assertSame(0, $stored->writes); }
	}

	public function testExpiredClaimCanBeSupersededButOldNonceCannotSubmit(): void {
		$state = $this->claimed();
		$state['ai_started_at'] = time() - 301;
		[$game, $stored] = $this->scenario($state);
		$newNonce = $game->claimAi(self::ID);
		self::assertNotSame(self::NONCE, $newNonce);
		self::assertSame($newNonce, $stored->state['ai_nonce']);
		try {
			$game->submitAiTurn(self::ID, self::NONCE, []);
			self::fail('Superseded AI claim must not commit.');
		} catch (RuntimeException $error) {
			self::assertSame('arena.invalid_ai_claim', $error->getMessage());
		}
		self::assertSame(1, $stored->writes);
	}

	public function testSubmitAiTurnAtomicallyAppliesActionsAndRadio(): void {
		[$game, $stored] = $this->scenario($this->claimed());
		$state = $game->submitAiTurn(self::ID, self::NONCE, [
			['unit' => 'a_guardian', 'type' => 'guard']
		], 'Test radio');
		self::assertSame('human', $state['phase']);
		self::assertSame(2, $state['round']);
		self::assertSame(1, $state['revision']);
		self::assertNull($stored->state['ai_nonce']);
		self::assertCount(1, array_filter($state['events'],
			static fn(array $event): bool => $event['key'] === 'nexus_radio'));
		self::assertSame(1, $stored->writes);
	}

	public function testSubmitAiRejectsInvalidClaimBeforeWrites(): void {
		[$game, $stored] = $this->scenario($this->claimed());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.invalid_ai_claim');
		try { $game->submitAiTurn(self::ID, 'wrong', []); }
		finally { self::assertSame(0, $stored->writes); }
	}

	public function testTooManyAiCommandsAreRefusedAtomically(): void {
		[$game, $stored] = $this->scenario($this->claimed());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.too_many_commands');
		try { $game->submitAiTurn(self::ID, self::NONCE, array_fill(0, 7, [])); }
		finally { self::assertSame(0, $stored->writes); }
	}

	public function testInvalidSecondCommandCannotPartiallyCommit(): void {
		[$game, $stored] = $this->scenario($this->claimed());
		$this->expectException(\InvalidArgumentException::class);
		try {
			$game->submitAiTurn(self::ID, self::NONCE, [
				['unit' => 'a_scout', 'type' => 'guard'],
				['unit' => 'a_scout', 'type' => 'guard']
			]);
		} finally {
			self::assertSame(0, $stored->writes);
			self::assertFalse($stored->state['units']['a_scout']['guard']);
		}
	}

	public function testAiEliminationFinishesWithoutAnExtraRound(): void {
		$state = $this->claimed();
		$state['units']['h_guardian']['hp'] = 0;
		$state['units']['h_ranger']['hp'] = 0;
		$state['units']['h_scout']['hp'] = 1;
		$state['units']['h_scout']['x'] = 7;
		$state['units']['h_scout']['y'] = 3;
		[$game, $stored] = $this->scenario($state);
		$ended = $game->submitAiTurn(self::ID, self::NONCE, [[
			'unit' => 'a_guardian', 'type' => 'attack', 'target' => 'h_scout'
		]]);
		self::assertSame('ai', $ended['winner']);
		self::assertSame('finished', $ended['phase']);
		self::assertSame(1, $ended['round']);
		self::assertNull($stored->state['ai_nonce']);
		self::assertSame(1, $stored->writes);
	}

	public function testReleaseRestoresOnlyMatchingPendingClaim(): void {
		[$game, $stored] = $this->scenario($this->claimed());
		$game->releaseAi(self::ID, 'wrong');
		self::assertSame(0, $stored->writes);
		$game->releaseAi(self::ID, self::NONCE);
		self::assertSame('ai', $stored->state['phase']);
		self::assertNull($stored->state['ai_nonce']);
		self::assertSame(1, $stored->writes);
		$game->releaseAi(self::ID, self::NONCE);
		self::assertSame(1, $stored->writes);
	}

	public function testDeleteOnlyOwnMatches(): void {
		[$game, $stored] = $this->scenario(ArenaRules::create(self::ID));
		$game->deleteOwnMatches();
		self::assertFalse($stored->exists);
		self::assertSame(1, $stored->writes);
		self::assertStringContainsString("authority = 'host'", end($stored->queries));
		self::assertStringContainsString("owner_id = '17'", end($stored->queries));
	}

	public function testReplayOfFinishedMatchIsDerivedWithoutDatabaseWrite(): void {
		$state = ArenaRules::create(self::ID);
		for ($round = 0; $round < 10; $round++) {
			$state = ArenaRules::completePhase($state, 'human');
			$state['phase'] = 'ai_running';
			$state = ArenaRules::completePhase($state, 'ai');
		}
		[$game, $stored] = $this->scenario($state);
		$frames = $game->replay(self::ID);
		self::assertCount(count($state['events']), $frames);
		self::assertSame('finished', end($frames)['state']['phase']);
		self::assertSame(0, $stored->writes);
	}

	/** @return array{ArenaGameService,ArenaStoredMatch} */
	private function scenario(array $state): array {
		$database = $this->createMock(IDatabase::class);
		$stored = new ArenaStoredMatch($database, $state);
		$users = $this->createMock(IUsermanager::class);
		$users->method('getUser')->willReturn(User::fromArray(['id' => 17]));
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('host');
		$authorization->method('decide')->willReturn(AuthorizationDecision::allow());
		$game = new ArenaGameService(new ArenaMatchRepository($database),
			new ArenaAccessService($users, $authorization));
		return [$game, $stored];
	}

	private function claimed(): array {
		$state = ArenaRules::create(self::ID);
		$state['phase'] = 'ai_running';
		$state['ai_nonce'] = self::NONCE;
		$state['ai_started_at'] = time();
		return $state;
	}
}
