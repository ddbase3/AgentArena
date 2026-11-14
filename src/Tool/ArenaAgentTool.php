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
use Base3\AssistantFoundation\Api\IAgentContext;
use Base3\CoreFoundation\Api\IMvcView;
use InvalidArgumentException;
use Base3\MissionBay\Api\IAgentTool;
use Base3\MissionBay\Resource\AbstractAgentResource;
use RuntimeException;

/**
 * One discoverable MissionBay resource offering two Arena functions.
 * Per-function schemas and safety semantics remain independent.
 * Both operations require the same authorized one-time AI execution claim.
 */
final class ArenaAgentTool extends AbstractAgentResource implements IAgentTool {

	public function __construct(
		private readonly ArenaGameService $game,
		private readonly IMvcView $view,
		?string $id = null
	) {
		parent::__construct($id);
	}

	public static function getName(): string {
		return 'arenaagenttool';
	}

	public function getDescription(): string {
		return $this->messages()['tool_arena_description'];
	}

	/** @return array<int,array<string,mixed>> */
	public function getToolDefinitions(): array {
		$text = $this->messages();
		return [[
			'type' => 'function',
			'label' => $text['tool_state_title'],
			'category' => 'game',
			'tags' => ['agentarena', 'state', 'strategy'],
			'priority' => 90,
			'readOnlyHint' => true,
			'mutation' => false,
			'requiresApproval' => false,
			'function' => [
				'name' => 'arena_get_state',
				'description' => $text['tool_state_description'],
				'parameters' => [
					'type' => 'object',
					'properties' => new \stdClass(),
					'required' => [],
					'additionalProperties' => false
				]
			]
		], [
			'type' => 'function',
			'label' => $text['tool_submit_title'],
			'category' => 'game',
			'tags' => ['agentarena', 'game-turn', 'mutation'],
			'priority' => 90,
			'readOnlyHint' => false,
			'mutation' => true,
			'sideEffectHint' => true,
			// Arena's human player already requested this specific AI phase.
			// This is not a general mutation exemption: no host resources are writable.
			'requiresApproval' => false,
			'commitGuardRequired' => false,
			'function' => [
				'name' => 'arena_submit_turn',
				'description' => $text['tool_submit_description'],
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'radio' => [
							'type' => 'string', 'maxLength' => 160,
							'description' => $text['tool_radio_description']
						],
						'commands' => [
							'type' => 'array',
							'maxItems' => 6,
							'items' => [
								'type' => 'object',
								'additionalProperties' => false,
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
					'required' => ['commands'],
					'additionalProperties' => false
				]
			]
		]];
	}

	/** @return array<string,mixed> */
	public function callTool(string $name, array $arguments, IAgentContext $context): array {
		if ($name !== 'arena_get_state' && $name !== 'arena_submit_turn') {
			throw new InvalidArgumentException('arena.invalid_action');
		}
		if ($name === 'arena_get_state' && $arguments !== []) {
			throw new InvalidArgumentException('arena.invalid_action');
		}

		[$matchId, $nonce] = $this->requireClaim($context);

		if ($name === 'arena_get_state') {
			return ['ok' => true, 'state' => $this->game->toolSnapshot($matchId, $nonce)];
		}

		$commands = $arguments['commands'] ?? null;
		if (array_diff(array_keys($arguments), ['commands', 'radio']) !== [] ||
			!is_array($commands) ||
			!array_is_list($commands) || count($commands) > 6) {
			return ['ok' => false, 'error' => 'arena.invalid_action'];
		}
		foreach ($commands as $command) {
			if (!is_array($command) || array_is_list($command) ||
				!is_string($command['unit'] ?? null) || !is_string($command['type'] ?? null)) {
				return ['ok' => false, 'error' => 'arena.invalid_action'];
			}
		}
		$radio = $arguments['radio'] ?? '';
		if (!is_string($radio)) { return ['ok' => false, 'error' => 'arena.invalid_radio']; }
		try {
			return ['ok' => true, 'state' => $this->game->submitAiTurn($matchId, $nonce, $commands, $radio)];
		} catch (InvalidArgumentException $error) {
			// A rejected full turn is a recoverable observation, never a partial commit.
			return ['ok' => false, 'error' => $error->getMessage()];
		}
	}

	/** @return array{0:string,1:string} */
	private function requireClaim(IAgentContext $context): array {
		$matchId = $context->getVar('arena_match_id');
		$nonce = $context->getVar('arena_nonce');
		if ($context->getVar('agent_id') !== 'agentarena.opponent' ||
			!is_string($matchId) || !preg_match('/^[a-f0-9]{32}$/D', $matchId) ||
			!is_string($nonce) || !preg_match('/^[a-f0-9]{48}$/D', $nonce)) {
			throw new RuntimeException('arena.invalid_ai_claim');
		}
		return [$matchId, $nonce];
	}

	/** @return array<string,string> */
	private function messages(): array {
		$this->view->setPath(DIR_PLUGIN . 'AgentArena');
		$this->view->loadBricks('Display');
		$text = $this->view->getBricks('agentarenadisplay');
		if (!is_array($text) || !isset($text['tool_arena_description'],
			$text['tool_state_title'], $text['tool_state_description'],
			$text['tool_submit_title'], $text['tool_submit_description'],
			$text['tool_radio_description'])) {
			throw new RuntimeException('arena.translations_unavailable');
		}
		return $text;
	}
}
