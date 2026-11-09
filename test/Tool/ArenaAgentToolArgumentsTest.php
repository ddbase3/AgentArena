<?php declare(strict_types=1);

namespace AgentArena\Test\Tool;

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
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ArenaAgentToolArgumentsTest extends TestCase {

	private const ID = '0123456789abcdef0123456789abcdef';
	private const NONCE = '0123456789abcdef0123456789abcdef0123456789abcdef';

	public function testReadFunctionRejectsArgumentsBeforeLoadingMatch(): void {
		$database = $this->createMock(IDatabase::class);
		$database->expects(self::never())->method('singleQuery');
		$tool = $this->tool($database);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('arena.invalid_action');
		$tool->callTool('arena_get_state', ['match_id' => self::ID], $this->context());
	}

	public function testInvalidSubmissionSchemaNeverReachesPersistence(): void {
		$database = $this->createMock(IDatabase::class);
		$database->expects(self::never())->method('singleQuery');
		$database->expects(self::never())->method('nonQuery');
		$tool = $this->tool($database);
		$cases = [
			[],
			['commands' => 'wrong'],
			['commands' => ['not' => 'a-list']],
			['commands' => array_fill(0, 7, ['unit' => 'a_scout', 'type' => 'guard'])],
			['commands' => [], 'unrecognized' => true],
			['commands' => [['unit' => 'a_scout']]],
			['commands' => [['unit' => 99, 'type' => 'guard']]],
			['commands' => [['unit' => 'a_scout', 'type' => 1]]],
			['commands' => [['unit', 'type']]]
		];
		foreach ($cases as $arguments) {
			self::assertSame(['ok' => false, 'error' => 'arena.invalid_action'],
				$tool->callTool('arena_submit_turn', $arguments, $this->context()));
		}
		self::assertSame(['ok' => false, 'error' => 'arena.invalid_radio'],
			$tool->callTool('arena_submit_turn', ['commands' => [], 'radio' => 99], $this->context()));
	}

	public function testInvalidAgentIdentityAndMalformedClaimsAreRejected(): void {
		$database = $this->createMock(IDatabase::class);
		$database->expects(self::never())->method('singleQuery');
		$tool = $this->tool($database);
		foreach ([
			['agent_id' => 'other'], ['arena_match_id' => str_repeat('x', 32)],
			['arena_match_id' => 17], ['arena_nonce' => str_repeat('f', 47)],
			['arena_nonce' => ''], ['arena_nonce' => 123]
		] as $override) {
			try {
				$tool->callTool('arena_get_state', [], $this->context($override));
				self::fail('An untrusted agent execution must not read a game.');
			} catch (RuntimeException $error) {
				self::assertSame('arena.invalid_ai_claim', $error->getMessage());
			}
		}
	}

	public function testMissingTranslationsAreRejectedForToolCatalog(): void {
		$database = $this->createMock(IDatabase::class);
		$view = $this->createMock(IMvcView::class);
		$view->method('getBricks')->willReturn([]);
		$tool = $this->tool($database, $view);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.translations_unavailable');
		$tool->getToolDefinitions();
	}

	private function tool(IDatabase $database, ?IMvcView $view = null): ArenaAgentTool {
		$database->method('escape')->willReturnCallback(static fn(string $value): string => addslashes($value));
		$users = $this->createMock(IUsermanager::class);
		$users->method('getUser')->willReturn(User::fromArray(['id' => 17]));
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('host');
		$authorization->method('decide')->willReturn(AuthorizationDecision::allow());
		$game = new ArenaGameService(new ArenaMatchRepository($database),
			new ArenaAccessService($users, $authorization));
		$view ??= $this->createMock(IMvcView::class);
		return new ArenaAgentTool($game, $view);
	}

	private function context(array $overrides = []): IAgentContext {
		$values = array_replace([
			'agent_id' => 'agentarena.opponent',
			'arena_match_id' => self::ID,
			'arena_nonce' => self::NONCE
		], $overrides);
		$context = $this->createMock(IAgentContext::class);
		$context->method('getVar')->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? null);
		return $context;
	}
}
