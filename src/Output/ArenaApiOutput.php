<?php declare(strict_types=1);

namespace AgentArena\Output;

use AgentArena\Service\ArenaAccessService;
use AgentArena\Service\ArenaAgentTurnService;
use AgentArena\Service\ArenaGameService;
use Base3\Api\IMvcView;
use Base3\Api\IOutput;
use Base3\Api\IRequest;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** One JSON output through existing BASE3 routing, not a new router or permission layer. */
final class ArenaApiOutput implements IOutput {

	public function __construct(
		private readonly IRequest $request,
		private readonly IMvcView $view,
		private readonly ArenaAccessService $access,
		private readonly ArenaGameService $game,
		private readonly ArenaAgentTurnService $agent
	) {}

	public static function getName(): string { return 'arenaapioutput'; }

	public function getOutput(string $out = 'html', bool $final = false): string {
		if ($out !== 'json') { return ''; }
		header('Content-Type: application/json; charset=UTF-8');
		$this->view->setPath(DIR_PLUGIN . 'AgentArena');
		$this->view->loadBricks('Display');
		$translations = $this->view->getBricks('agentarenadisplay');
		if (!is_array($translations)) { throw new RuntimeException('Arena translations unavailable.'); }
		try {
			$this->access->requireActor();
			if (strtoupper((string)$this->request->server('REQUEST_METHOD', '')) !== 'POST') {
				throw new InvalidArgumentException('arena.post_only');
			}
			$this->requireSameOriginJson();
			$body = $this->request->getJsonBody();
			$action = $body['action'] ?? null;
			if (!is_string($action)) { throw new InvalidArgumentException('arena.invalid_action'); }
			if ($action === 'start') {
				$mapId = $body['map_id'] ?? 'standard';
				if (!is_string($mapId)) { throw new InvalidArgumentException('arena.invalid_map'); }
				$payload = ['state' => $this->game->createMatch($mapId)];
			} elseif ($action === 'history') {
				$payload = ['matches' => $this->game->history()];
			} elseif ($action === 'resume') {
				$payload = ['state' => $this->game->latestMatch()];
			} elseif ($action === 'delete_all') {
				$this->game->deleteOwnMatches();
				$payload = ['state' => null];
			} else {
				$id = $body['match_id'] ?? null;
				if (!is_string($id)) { throw new InvalidArgumentException('arena.match_not_found'); }
				$payload = match($action) {
					'snapshot' => ['state' => $this->game->snapshot($id)],
					'replay' => ['frames' => $this->game->replay($id)],
					'command' => ['state' => $this->game->humanCommand($id,
						$this->requireRevision($body), $this->requireCommand($body))],
					'end' => ['state' => $this->game->endHumanTurn($id, $this->requireRevision($body))],
					'ai' => $this->agent->playAiTurn($id),
					default => throw new InvalidArgumentException('arena.invalid_action')
				};
			}
			return json_encode(['ok' => true] + $payload, JSON_THROW_ON_ERROR);
		} catch (Throwable $error) {
			$code = $error->getMessage();
			$key = str_starts_with($code, 'arena.') ? substr($code, 6) : 'unexpected_error';
			if (!array_key_exists($key, $translations)) { $key = 'unexpected_error'; }
			$status = in_array($key, ['unauthenticated', 'access_denied', 'invalid_origin'], true) ? 403 :
				(in_array($key, ['stale_state', 'invalid_phase', 'invalid_ai_claim'], true) ? 409 : 400);
			if ($key === 'invalid_content_type') { $status = 415; }
			if ($key === 'unexpected_error' || $key === 'agent_no_turn') { $status = 500; }
			http_response_code($status);
			return json_encode(['ok' => false, 'error' => [
				'code' => $key, 'message' => $translations[$key]
			]], JSON_THROW_ON_ERROR);
		}
	}

	/** Accept only the browser's same-origin JSON transport, without a second token lifecycle. */
	private function requireSameOriginJson(): void {
		if ((string)$this->request->server('HTTP_SEC_FETCH_SITE', '') !== 'same-origin') {
			throw new RuntimeException('arena.invalid_origin');
		}
		$contentType = strtolower(trim(explode(';',
			(string)$this->request->server('CONTENT_TYPE', ''))[0]));
		if ($contentType !== 'application/json') {
			throw new InvalidArgumentException('arena.invalid_content_type');
		}
	}

	private function requireRevision(array $body): int {
		if (!is_int($body['revision'] ?? null) || $body['revision'] < 0) {
			throw new InvalidArgumentException('arena.stale_state');
		}
		return $body['revision'];
	}

	private function requireCommand(array $body): array {
		if (!is_array($body['command'] ?? null)) {
			throw new InvalidArgumentException('arena.invalid_action');
		}
		return $body['command'];
	}
}
