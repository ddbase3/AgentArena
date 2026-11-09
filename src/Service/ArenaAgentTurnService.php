<?php declare(strict_types=1);

namespace AgentArena\Service;

use AssistantFoundation\Api\IAgentConfigurationService;
use AssistantFoundation\Api\IAgentExecutionService;
use AssistantFoundation\Dto\AgentExecutionRequest;
use RuntimeException;

/** Calls the existing agent runtime; game actions occur only through the native tool profile. */
final class ArenaAgentTurnService {

	public function __construct(
		private readonly ArenaGameService $game,
		private readonly IAgentConfigurationService $agents,
		private readonly IAgentExecutionService $execution
	) {}

	public function playAiTurn(string $matchId): array {
		$settings = $this->agents->getAgent('agentarena.opponent');
		if ($settings === null || ($settings['enabled'] ?? false) !== true) {
			throw new RuntimeException('arena.agent_not_enabled');
		}
		$nonce = $this->game->claimAi($matchId);
		try {
			$prompt = 'Play the current AI turn in Agent Arena. Call the Arena tool function ' .
				'arena_get_state (use its advertised namespaced name) and study its live state, ' .
				'map_id, walls, control points, legal options, tactical_briefing and recent human actions. Adapt your strategy ' .
				'to the actual prior play, not guesses. Submit one legal complete turn with ' .
				'arena_submit_turn using the advertised function name. Include an optional ' .
				'short, in-character German radio message in the radio argument (maximum ' .
				'160 characters, no control characters); this message is shown to the ' .
				'player only after the move is confirmed. The entire turn is rejected ' .
				'if any command is invalid. An empty commands list is allowed. ' .
				'Never use text as a replacement for a tool action.';
			$result = $this->execution->execute(new AgentExecutionRequest(
				$settings,
				['system' => (string)($settings['system_prompt'] ?? ''),
					'prompt' => $prompt, 'mode' => 'manual_agent'],
				['agent_id' => 'agentarena.opponent',
					'agent_label' => (string)($settings['label'] ?? 'NEXUS'),
					'agent_config' => $settings,
					'agent_run_mode' => 'manual',
					'agent_user_prompt' => $prompt,
					'chatbot_config_group' => 'agent',
					'chatbot_config_name' => 'agentarena.opponent',
					'conversation_channel_id' => 'agentarena.match',
					'conversation_id' => $matchId,
					'arena_match_id' => $matchId,
					'arena_nonce' => $nonce]
			));
			$state = $this->game->snapshot($matchId);
			if ($state['phase'] === 'ai_running') {
				throw new RuntimeException('arena.agent_no_turn');
			}
			// The atomic tool commit is authoritative even if the agent output is empty.
			return ['state' => $state, 'warnings' => $result->getWarnings()];
		} finally {
			// A timeout, model failure or missing tool call must not leave a permanent claim.
			$this->game->releaseAi($matchId, $nonce);
		}
	}
}
