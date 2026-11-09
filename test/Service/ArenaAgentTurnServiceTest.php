<?php declare(strict_types=1);

namespace AgentArena\Test\Service;

use AgentArena\Game\ArenaRules;
use AgentArena\Repository\ArenaMatchRepository;
use AgentArena\Service\ArenaAccessService;
use AgentArena\Service\ArenaAgentTurnService;
use AgentArena\Service\ArenaGameService;
use AgentArena\Test\Fixture\ArenaStoredMatch;
use AssistantFoundation\Api\IAgentConfigurationService;
use AssistantFoundation\Api\IAgentExecutionService;
use AssistantFoundation\Dto\AgentExecutionRequest;
use AssistantFoundation\Dto\AgentExecutionResult;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Database\Api\IDatabase;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\User;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ArenaAgentTurnServiceTest extends TestCase {

	private const ID = '0123456789abcdef0123456789abcdef';

	public function testUnknownAgentIsRejectedBeforeClaimAndExecution(): void {
		[$game, $stored] = $this->scenario();
		$agents = $this->createMock(IAgentConfigurationService::class);
		$agents->expects(self::once())->method('getAgent')->with('agentarena.opponent')->willReturn(null);
		$execution = $this->createMock(IAgentExecutionService::class);
		$execution->expects(self::never())->method('execute');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.agent_not_enabled');
		try { (new ArenaAgentTurnService($game, $agents, $execution))->playAiTurn(self::ID); }
		finally { self::assertSame(0, $stored->writes); }
	}

	public function testDisabledAgentIsRejectedWithoutClaim(): void {
		[$game, $stored] = $this->scenario();
		$agents = $this->agents(['enabled' => false]);
		$execution = $this->createMock(IAgentExecutionService::class);
		$execution->expects(self::never())->method('execute');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.agent_not_enabled');
		try { (new ArenaAgentTurnService($game, $agents, $execution))->playAiTurn(self::ID); }
		finally { self::assertSame(0, $stored->writes); }
	}

	public function testExecutedToolCommitReturnsPublicStateAndWarnings(): void {
		[$game, $stored] = $this->scenario();
		$agents = $this->agents([
			'enabled' => true,
			'system_prompt' => 'Play responsibly.',
			'label' => 'NEXUS',
			'tool_profiles' => ['arena-agent-tool-profile']
		]);
		$execution = $this->createMock(IAgentExecutionService::class);
		$execution->expects(self::once())->method('execute')->willReturnCallback(
			function(AgentExecutionRequest $request) use ($game): AgentExecutionResult {
				$config = $request->getAgentConfiguration();
				$inputs = $request->getInputs();
				$context = $request->getContext();
				self::assertSame(['arena-agent-tool-profile'], $config['tool_profiles']);
				self::assertSame('manual_agent', $inputs['mode']);
				self::assertSame('Play responsibly.', $inputs['system']);
				self::assertStringContainsString('arena_get_state', $inputs['prompt']);
				self::assertStringContainsString('arena_submit_turn', $inputs['prompt']);
				self::assertSame(self::ID, $context['arena_match_id']);
				self::assertSame('agentarena.opponent', $context['agent_id']);
				self::assertSame('agentarena.match', $context['conversation_channel_id']);
				self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/D', $context['arena_nonce']);
				$game->submitAiTurn(self::ID, $context['arena_nonce'], [], 'Turn completed');
				return new AgentExecutionResult([], ['usage info']);
			}
		);
		$response = (new ArenaAgentTurnService($game, $agents, $execution))->playAiTurn(self::ID);
		self::assertSame('human', $response['state']['phase']);
		self::assertSame(['usage info'], $response['warnings']);
		self::assertArrayNotHasKey('ai_nonce', $response['state']);
		self::assertSame(2, $stored->writes); // Claim and authoritative tool commit only.
	}

	public function testMissingToolCommitRaisesErrorAndReleasesClaim(): void {
		[$game, $stored] = $this->scenario();
		$execution = $this->createMock(IAgentExecutionService::class);
		$execution->method('execute')->willReturn(new AgentExecutionResult(['assistant' => ['message' => 'done']]));
		$service = new ArenaAgentTurnService($game, $this->agents(['enabled' => true]), $execution);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.agent_no_turn');
		try { $service->playAiTurn(self::ID); }
		finally {
			self::assertSame('ai', $stored->state['phase']);
			self::assertNull($stored->state['ai_nonce']);
			self::assertSame(2, $stored->writes);
		}
	}

	public function testRuntimeFailureReleasesClaimWithoutChangingHumanState(): void {
		[$game, $stored] = $this->scenario();
		$execution = $this->createMock(IAgentExecutionService::class);
		$execution->method('execute')->willThrowException(new RuntimeException('model failed'));
		$service = new ArenaAgentTurnService($game, $this->agents(['enabled' => true]), $execution);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('model failed');
		try { $service->playAiTurn(self::ID); }
		finally {
			self::assertSame('ai', $stored->state['phase']);
			self::assertSame(2, $stored->writes);
		}
	}

	public function testRuntimeExceptionAfterConfirmedToolCommitNeverRollsBackTurn(): void {
		[$game, $stored] = $this->scenario();
		$execution = $this->createMock(IAgentExecutionService::class);
		$execution->method('execute')->willReturnCallback(function(AgentExecutionRequest $request) use ($game): AgentExecutionResult {
			$game->submitAiTurn(self::ID, $request->getContext()['arena_nonce'], []);
			throw new RuntimeException('late output failure');
		});
		$service = new ArenaAgentTurnService($game, $this->agents(['enabled' => true]), $execution);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('late output failure');
		try { $service->playAiTurn(self::ID); }
		finally {
			self::assertSame('human', $stored->state['phase']);
			self::assertSame(2, $stored->writes);
		}
	}

	private function agents(array $settings): IAgentConfigurationService {
		$agents = $this->createMock(IAgentConfigurationService::class);
		$agents->method('getAgent')->willReturn($settings);
		return $agents;
	}

	/** @return array{ArenaGameService,ArenaStoredMatch} */
	private function scenario(): array {
		$state = ArenaRules::completePhase(ArenaRules::create(self::ID), 'human');
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
}
