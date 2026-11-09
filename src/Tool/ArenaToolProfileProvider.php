<?php declare(strict_types=1);

namespace AgentArena\Tool;

use AgentArena\Service\ArenaGameService;
use AssistantFoundation\Api\IAgentToolProfileProvider;
use AssistantFoundation\Api\IAgentToolSet;
use AssistantFoundation\Dto\AgentExecutionRequest;
use Base3\Api\IMvcView;
use RuntimeException;

/** Native AssistantFoundation tool-profile extension for run-scoped Arena tools. */
final class ArenaToolProfileProvider implements IAgentToolProfileProvider {

	public const PROFILE_ID = 'agentarena.match';

	public function __construct(
		private readonly ArenaGameService $game,
		private readonly IMvcView $view
	) {}

	public static function getName(): string { return 'arenatoolprofileprovider'; }

	public static function getProviderId(): string { return 'agentarena'; }

	/** Returns localized labels for the existing Agent Tool Profile administration UI. */
	public function getOptions(): array {
		return [['id' => self::PROFILE_ID, 'label' => $this->messages()['tool_profile_label']]];
	}

	public function hasProfile(string $profileId): bool { return $profileId === self::PROFILE_ID; }

	public function resolve(array $profileIds, AgentExecutionRequest $request): IAgentToolSet {
		if (!in_array(self::PROFILE_ID, $profileIds, true)) {
			throw new RuntimeException('arena.invalid_tool_profile');
		}
		$context = $request->getContext();
		if (($context['agent_id'] ?? null) !== 'agentarena.opponent' ||
			!is_string($context['arena_match_id'] ?? null) ||
			!is_string($context['arena_nonce'] ?? null)) {
			throw new RuntimeException('arena.invalid_ai_claim');
		}
		// No tool argument can replace the run-scoped match or execution nonce.
		$this->game->toolSnapshot($context['arena_match_id'], $context['arena_nonce']);
		return new ArenaToolSet($this->game, $context['arena_match_id'],
			$context['arena_nonce'], $this->messages());
	}

	private function messages(): array {
		$this->view->setPath(DIR_PLUGIN . 'AgentArena');
		$this->view->loadBricks('Display');
		$messages = $this->view->getBricks('agentarenadisplay');
		if (!is_array($messages)) { throw new RuntimeException('arena.translations_unavailable'); }
		return $messages;
	}
}
