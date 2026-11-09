<?php declare(strict_types=1);

namespace AgentArena\Test\Tool;

use AgentArena\Game\ArenaRules;
use AgentArena\Repository\ArenaMatchRepository;
use AgentArena\Service\ArenaAccessService;
use AgentArena\Service\ArenaGameService;
use AgentArena\Tool\ArenaAgentTool;
use AssistantFoundation\Api\IAgentContext;
use Base3\Api\IMvcView;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Database\Api\IDatabase;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\User;
use MissionBay\Api\IAgentResource;
use MissionBay\Api\IAgentTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__) . '/bootstrap.php';

/** Contract, authorization-claim and per-function mutation semantics for one MissionBay resource. */
final class ArenaAgentToolTest extends TestCase {

	private const MATCH_ID = '0123456789abcdef0123456789abcdef';
	private const NONCE = '0123456789abcdef0123456789abcdef0123456789abcdef';

	public function testOneToolResourcePublishesTwoIndependentFunctionSchemas(): void {
		$tool = new ArenaAgentTool($this->game($this->database()), $this->view());
		self::assertInstanceOf(IAgentResource::class, $tool);
		self::assertInstanceOf(IAgentTool::class, $tool);
		self::assertSame('arenaagenttool', $tool::getName());
		self::assertSame('Arena operations', $tool->getDescription());
		self::assertSame([], $tool->getDockDefinitions());
		$definitions = $tool->getToolDefinitions();
		self::assertCount(2, $definitions);
		self::assertSame(['arena_get_state', 'arena_submit_turn'],
			array_column(array_column($definitions, 'function'), 'name'));
		[$state, $submit] = $definitions;
		self::assertInstanceOf(\stdClass::class, $state['function']['parameters']['properties']);
		self::assertTrue($state['readOnlyHint']);
		self::assertFalse($state['mutation']);
		self::assertFalse($state['requiresApproval']);
		self::assertFalse($submit['readOnlyHint']);
		self::assertTrue($submit['mutation']);
		self::assertFalse($submit['requiresApproval']);
		self::assertFalse($submit['commitGuardRequired']);
		self::assertSame(['commands'], $submit['function']['parameters']['required']);
		self::assertSame('string', $submit['function']['parameters']['properties']['radio']['type']);
		self::assertSame(160, $submit['function']['parameters']['properties']['radio']['maxLength']);
	}

	public function testStateToolReturnsOnlyPublicClaimedMatch(): void {
		$database = $this->database($this->claimedState());
		$database->expects(self::never())->method('nonQuery');
		$tool = new ArenaAgentTool($this->game($database), $this->view());
		$result = $tool->callTool('arena_get_state', [], $this->context());
		self::assertTrue($result['ok']);
		self::assertSame('ai_running', $result['state']['phase']);
		self::assertArrayNotHasKey('ai_nonce', $result['state']);
		self::assertArrayNotHasKey('ai_started_at', $result['state']);
		self::assertArrayHasKey('tactical_briefing', $result['state']);
		self::assertCount(3, $result['state']['tactical_briefing']['control_occupancy']);
	}

	public function testMissingClaimIsRejectedBeforeDatabaseAccess(): void {
		$database = $this->database();
		$database->expects(self::never())->method('singleQuery');
		$tool = new ArenaAgentTool($this->game($database), $this->view());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.invalid_ai_claim');
		$tool->callTool('arena_get_state', [], $this->context(['arena_nonce' => '']));
	}

	public function testUnknownFunctionNeverReachesTheDomain(): void {
		$database = $this->database();
		$database->expects(self::never())->method('singleQuery');
		$database->expects(self::never())->method('nonQuery');
		$tool = new ArenaAgentTool($this->game($database), $this->view());
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('arena.invalid_action');
		$tool->callTool('arena_unknown', [], $this->context());
	}

	public function testInvalidCommandsNeverUpdateTheDatabase(): void {
		$database = $this->database($this->claimedState());
		$database->expects(self::never())->method('nonQuery');
		$tool = new ArenaAgentTool($this->game($database), $this->view());
		self::assertSame(['ok' => false, 'error' => 'arena.invalid_action'],
			$tool->callTool('arena_submit_turn', ['commands' => ['invalid']], $this->context()));
		self::assertSame(['ok' => false, 'error' => 'arena.invalid_move'],
			$tool->callTool('arena_submit_turn', ['commands' => [[
				'unit' => 'a_scout', 'type' => 'move', 'x' => 0, 'y' => 0
			]]], $this->context()));
	}

	public function testInvalidRadioCannotCommitTheAiTurn(): void {
		$database = $this->database($this->claimedState());
		$database->expects(self::never())->method('nonQuery');
		$tool = new ArenaAgentTool($this->game($database), $this->view());
		$result = $tool->callTool('arena_submit_turn', [
			'commands' => [], 'radio' => str_repeat('A', 161)
		], $this->context());
		self::assertSame(['ok' => false, 'error' => 'arena.invalid_radio'], $result);
	}

	public function testValidTurnCommitsThroughTheOwner(): void {
		$database = $this->database($this->claimedState());
		$database->expects(self::once())->method('nonQuery');
		$database->method('affectedRows')->willReturn(1);
		$tool = new ArenaAgentTool($this->game($database), $this->view());
		$commands = [['unit' => 'a_guardian', 'type' => 'guard']];
		$result = $tool->callTool('arena_submit_turn', [
			'commands' => $commands, 'radio' => 'Ich halte meine Position.'
		], $this->context());
		self::assertTrue($result['ok']);
		self::assertSame('human', $result['state']['phase']);
		self::assertSame(1, $result['state']['revision']);
		$radio = array_values(array_filter($result['state']['events'],
			static fn(array $event): bool => $event['key'] === 'nexus_radio'));
		self::assertSame('Ich halte meine Position.', $radio[0]['text']);
	}

	private function claimedState(): array {
		$state = ArenaRules::create(self::MATCH_ID);
		$state['phase'] = 'ai_running';
		$state['ai_nonce'] = self::NONCE;
		$state['ai_started_at'] = time();
		return $state;
	}

	private function database(?array $state = null): IDatabase {
		$db = $this->createMock(IDatabase::class);
		if ($state !== null) {
			$db->method('singleQuery')->willReturn([
				'revision' => 0,
				'payload' => json_encode($state, JSON_THROW_ON_ERROR)
			]);
		}
		$db->method('escape')->willReturnCallback(static fn(string $value): string => addslashes($value));
		return $db;
	}

	private function game(IDatabase $db): ArenaGameService {
		$users = $this->createMock(IUsermanager::class);
		$users->method('getUser')->willReturn(User::fromArray(['id' => 12]));
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('test-host');
		$authorization->method('decide')->willReturn(AuthorizationDecision::allow());
		return new ArenaGameService(new ArenaMatchRepository($db), new ArenaAccessService($users, $authorization));
	}

	/** @param array<string,mixed> $overrides */
	private function context(array $overrides = []): IAgentContext {
		$values = array_replace([
			'agent_id' => 'agentarena.opponent',
			'arena_match_id' => self::MATCH_ID,
			'arena_nonce' => self::NONCE
		], $overrides);
		$context = $this->createMock(IAgentContext::class);
		$context->method('getVar')->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? null);
		return $context;
	}

	private function view(): IMvcView {
		$view = $this->createMock(IMvcView::class);
		$view->method('getBricks')->willReturn([
			'tool_arena_description' => 'Arena operations',
			'tool_state_title' => 'Read state',
			'tool_state_description' => 'Read authorized arena state',
			'tool_submit_title' => 'Submit turn',
			'tool_submit_description' => 'Submit the whole AI turn atomically',
			'tool_radio_description' => 'An optional NEXUS radio message'
		]);
		return $view;
	}
}
