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


namespace Base3\AgentArena\Test\Output;

use Base3\AgentArena\Game\ArenaRules;
use Base3\AgentArena\Output\ArenaApiOutput;
use Base3\AgentArena\Repository\ArenaMatchRepository;
use Base3\AgentArena\Service\ArenaAccessService;
use Base3\AgentArena\Service\ArenaAgentTurnService;
use Base3\AgentArena\Service\ArenaGameService;
use Base3\AgentArena\Test\Fixture\ArenaStoredMatch;
use Base3\AssistantFoundation\Api\IAgentConfigurationService;
use Base3\AssistantFoundation\Api\IAgentExecutionService;
use Base3\CoreFoundation\Api\IMvcView;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Authorization\Api\IAuthorizationService;
use Base3\CoreFoundation\Authorization\Dto\AuthorizationDecision;
use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\Usermanager\Api\IUsermanager;
use Base3\CoreFoundation\Usermanager\User;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/bootstrap.php';

/** Each HTTP response test owns its process because the endpoint emits PHP headers. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ArenaApiOutputTest extends TestCase {

	private const ID = '0123456789abcdef0123456789abcdef';

	public function testNonJsonOutputHasNoEffect(): void {
		[$api, $stored] = $this->controller(['action' => 'delete_all']);
		self::assertSame('', $api->getOutput('html'));
		self::assertSame(0, $stored->writes);
	}

	public function testResumeReadsOnlyOwnedMatch(): void {
		[$api, $stored] = $this->controller(['action' => 'resume']);
		$response = $this->json($api);
		self::assertTrue($response['ok']);
		self::assertSame(self::ID, $response['state']['id']);
		self::assertSame(0, $stored->writes);
	}

	public function testStartUsesSelectedMap(): void {
		[$api, $stored] = $this->controller(['action' => 'start', 'map_id' => 'bastion']);
		$response = $this->json($api);
		self::assertTrue($response['ok']);
		self::assertSame('bastion', $response['state']['map_id']);
		self::assertSame(1, $stored->writes);
	}

	public function testHistoryAndDeleteOwnMatches(): void {
		[$history, $stored] = $this->controller(['action' => 'history']);
		self::assertSame(['ok' => true, 'matches' => []], $this->json($history));
		self::assertSame(0, $stored->writes);
		[$delete, $deleted] = $this->controller(['action' => 'delete_all']);
		self::assertSame(['ok' => true, 'state' => null], $this->json($delete));
		self::assertFalse($deleted->exists);
	}

	public function testSnapshotCommandAndEndTurnActions(): void {
		[$snapshot] = $this->controller(['action' => 'snapshot', 'match_id' => self::ID]);
		self::assertSame('human', $this->json($snapshot)['state']['phase']);
		[$command, $stored] = $this->controller([
			'action' => 'command', 'match_id' => self::ID, 'revision' => 0,
			'command' => ['type' => 'move', 'unit' => 'h_scout', 'x' => 2, 'y' => 1]
		]);
		self::assertSame(2, $this->json($command)['state']['units']['h_scout']['x']);
		self::assertSame(1, $stored->writes);
		[$end] = $this->controller(['action' => 'end', 'match_id' => self::ID, 'revision' => 0]);
		self::assertSame('ai', $this->json($end)['state']['phase']);
	}

	public function testWrongMethodIsRejected(): void {
		[$api, $stored] = $this->controller(['action' => 'resume'], null, [
			'REQUEST_METHOD' => 'GET', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
			'CONTENT_TYPE' => 'application/json'
		]);
		$this->error($api, 'post_only', 400);
		self::assertSame(0, $stored->writes);
	}

	public function testCrossOriginIsRejectedBeforeDatabaseRead(): void {
		[$api, $stored] = $this->controller(['action' => 'resume'], null, [
			'REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'cross-site',
			'CONTENT_TYPE' => 'application/json'
		]);
		$this->error($api, 'invalid_origin', 403);
		self::assertSame([], $stored->queries);
	}

	public function testIncorrectContentTypeIs415(): void {
		[$api] = $this->controller(['action' => 'resume'], null, [
			'REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
			'CONTENT_TYPE' => 'text/plain'
		]);
		$this->error($api, 'invalid_content_type', 415);
	}

	public function testUnauthenticatedAndDeniedActorsGet403(): void {
		[$anonymous] = $this->controller(['action' => 'resume'], null, [], false);
		$this->error($anonymous, 'unauthenticated', 403);
		[$denied] = $this->controller(['action' => 'resume'], null, [], true, false);
		$this->error($denied, 'access_denied', 403);
	}

	public function testInvalidRequestPayloadsNeverMutate(): void {
		$cases = [
			[['action' => 42], 'invalid_action', 400],
			[['action' => 'start', 'map_id' => []], 'invalid_map', 400],
			[['action' => 'start', 'map_id' => 'absent'], 'invalid_map', 400],
			[['action' => 'snapshot'], 'match_not_found', 400],
			[['action' => 'command', 'match_id' => self::ID, 'revision' => '0', 'command' => []], 'stale_state', 409],
			[['action' => 'command', 'match_id' => self::ID, 'revision' => 0], 'invalid_action', 400],
			[['action' => 'end', 'match_id' => self::ID, 'revision' => -1], 'stale_state', 409],
			[['action' => 'made_up', 'match_id' => self::ID], 'invalid_action', 400]
		];
		foreach ($cases as [$body, $error, $status]) {
			[$api, $stored] = $this->controller($body);
			$this->error($api, $error, $status);
			self::assertSame(0, $stored->writes);
		}
	}

	public function testAiActionHonorsAgentEnablement(): void {
		$state = ArenaRules::completePhase(ArenaRules::create(self::ID), 'human');
		[$api, $stored] = $this->controller(['action' => 'ai', 'match_id' => self::ID], $state);
		$this->error($api, 'agent_not_enabled', 400);
		self::assertSame(0, $stored->writes);
	}

	public function testUnavailableReplayReturnsTranslatedDomainError(): void {
		[$api] = $this->controller(['action' => 'snapshot', 'match_id' => self::ID]);
		// Malformed repository payloads must not leak exception details through the API.
		[$broken] = $this->controller(['action' => 'replay', 'match_id' => self::ID]);
		$this->error($broken, 'replay_unavailable', 400);
		self::assertSame('human', $this->json($api)['state']['phase']);
	}

	/** @return array{ArenaApiOutput,ArenaStoredMatch} */
	private function controller(
		array $body,
		?array $state = null,
		array $headers = [],
		bool $authenticated = true,
		bool $allowed = true
	): array {
		$headers += [
			'REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'same-origin',
			'CONTENT_TYPE' => 'application/json'
		];
		$request = $this->createMock(IRequest::class);
		$request->method('server')->willReturnCallback(
			static fn(string $name, mixed $default = null): mixed => $headers[$name] ?? $default
		);
		$request->method('getJsonBody')->willReturn($body);
		$view = $this->createMock(IMvcView::class);
		$view->method('getBricks')->willReturn($this->translations());
		$users = $this->createMock(IUsermanager::class);
		$users->method('getUser')->willReturn($authenticated ? User::fromArray(['id' => 17]) : null);
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('host');
		$authorization->method('decide')->willReturn($allowed ? AuthorizationDecision::allow() : AuthorizationDecision::deny());
		$access = new ArenaAccessService($users, $authorization);
		$database = $this->createMock(IDatabase::class);
		$stored = new ArenaStoredMatch($database, $state ?? ArenaRules::create(self::ID));
		$database->method('multiQuery')->willReturn([]);
		$game = new ArenaGameService(new ArenaMatchRepository($database), $access);
		$agents = $this->createMock(IAgentConfigurationService::class);
		$agents->method('getAgent')->willReturn(['enabled' => false]);
		$execution = $this->createMock(IAgentExecutionService::class);
		$agent = new ArenaAgentTurnService($game, $agents, $execution);
		return [new ArenaApiOutput($request, $view, $access, $game, $agent), $stored];
	}

	private function json(ArenaApiOutput $api): array {
		http_response_code(200);
		return json_decode($api->getOutput('json'), true, 512, JSON_THROW_ON_ERROR);
	}

	private function error(ArenaApiOutput $api, string $expected, int $status): void {
		$response = $this->json($api);
		self::assertFalse($response['ok']);
		self::assertSame($expected, $response['error']['code']);
		self::assertSame($status, http_response_code());
		self::assertSame('message:' . $expected, $response['error']['message']);
	}

	private function translations(): array {
		$keys = [
			'unauthenticated', 'access_denied', 'post_only', 'invalid_origin', 'invalid_content_type',
			'invalid_action', 'invalid_map', 'match_not_found', 'stale_state', 'invalid_phase',
			'invalid_ai_claim', 'agent_not_enabled', 'agent_no_turn', 'replay_unavailable',
			'unexpected_error'
		];
		return array_combine($keys, array_map(static fn(string $key): string => 'message:' . $key, $keys));
	}
}
