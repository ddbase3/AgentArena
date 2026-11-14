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


namespace Base3\AgentArena\Tool;

use Base3\AgentArena\Service\ArenaGameService;
use Base3\AssistantFoundation\Api\IAgentToolSet;
use Base3\AssistantFoundation\Dto\AgentCapability;
use Base3\AssistantFoundation\Dto\AgentCapabilityCatalog;
use Base3\AssistantFoundation\Dto\AgentToolResult;
use Throwable;

/** Two game-domain tools exposed through the configured native agent tool-profile service. */
final class ArenaToolSet implements IAgentToolSet {

	private AgentCapabilityCatalog $catalog;

	public function __construct(
		private readonly ArenaGameService $game,
		private readonly string $matchId,
		private readonly string $nonce,
		private readonly array $messages
	) {
		$this->catalog = new AgentCapabilityCatalog([
			$this->capability('arena_get_state', $this->messages['tool_state_description'], [
				'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false
			], false),
			$this->capability('arena_submit_turn', $this->messages['tool_submit_description'], [
				'type' => 'object', 'additionalProperties' => false,
				'properties' => [
					'commands' => [
						'type' => 'array', 'maxItems' => 6,
						'items' => [
							'type' => 'object', 'additionalProperties' => false,
							'required' => ['unit', 'type'],
							'properties' => [
								'unit' => ['type' => 'string', 'enum' => ['a_scout', 'a_guardian', 'a_ranger']],
								'type' => ['type' => 'string', 'enum' => ['move', 'attack', 'guard']],
								'x' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 8],
								'y' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6],
								'target' => ['type' => 'string', 'enum' => ['h_scout', 'h_guardian', 'h_ranger']]
							]
						]
					]
				],
				'required' => ['commands']
			], true)
		]);
	}

	public function getCatalog(): AgentCapabilityCatalog { return $this->catalog; }

	public function getWarnings(): array { return []; }

	public function execute(string $callId, string $toolName, array $arguments, array $metadata = []): AgentToolResult {
		try {
			if ($toolName === 'arena_get_state') {
				return AgentToolResult::success($callId, $toolName, $arguments,
					$this->game->toolSnapshot($this->matchId, $this->nonce));
			}
			if ($toolName === 'arena_submit_turn') {
				if (!isset($arguments['commands']) || !is_array($arguments['commands']) ||
					!array_is_list($arguments['commands'])) {
					throw new \InvalidArgumentException('arena.invalid_action');
				}
				return AgentToolResult::success($callId, $toolName, $arguments,
					$this->game->submitAiTurn($this->matchId, $this->nonce, $arguments['commands']));
			}
			return AgentToolResult::failure($callId, $toolName, $arguments,
					'unknown_tool', $this->messages['tool_unknown']);
		} catch (Throwable $error) {
			return AgentToolResult::failure($callId, $toolName, $arguments, 'arena_action_rejected',
					str_starts_with($error->getMessage(), 'arena.') ? $error->getMessage() : 'arena.unexpected_error');
		}
	}

	private function capability(string $name, string $description, array $parameters, bool $mutates): AgentCapability {
		return new AgentCapability(
			$name,
			$name === 'arena_get_state' ? $this->messages['tool_state_title'] : $this->messages['tool_submit_title'],
			$description,
			'agentarena',
			['arena', $mutates ? 'mutation' : 'read-only'],
			10,
			['type' => 'function', 'function' => [
				'name' => $name, 'description' => $description, 'parameters' => $parameters
			]],
			'agentarena', 'Agent Arena', true,
			['mutates' => $mutates]
		);
	}
}
