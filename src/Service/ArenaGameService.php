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


namespace Base3\AgentArena\Service;

use Base3\AgentArena\Game\ArenaRules;
use Base3\AgentArena\Repository\ArenaMatchRepository;
use RuntimeException;

/** Orchestrates authorized match operations and revision-checked persistence. */
final class ArenaGameService {

	public function __construct(
		private readonly ArenaMatchRepository $matches,
		private readonly ArenaAccessService $access
	) {}

	/** Erases every match belonging to the current host actor. */
	public function deleteOwnMatches(): void {
		$this->matches->deleteAllOwned($this->access->requireActor());
	}

	public function createMatch(string $mapId = 'standard'): array {
		$actor = $this->access->requireActor();
		$state = ArenaRules::create(bin2hex(random_bytes(16)), $mapId);
		$this->matches->create($actor, $state);
		return ArenaRules::publicState($state);
	}

	public function latestMatch(): ?array {
		$state = $this->matches->latestOwned($this->access->requireActor());
		return $state === null ? null : ArenaRules::publicState($state);
	}

	/** Lists the most recent authorized matches without a duplicate history store. */
	public function history(): array {
		return $this->matches->listOwned($this->access->requireActor());
	}

	/** Replays only finished, owner-accessible matches without modifying them. */
	public function replay(string $matchId): array {
		$state = $this->matches->loadOwned($matchId, $this->access->requireActor());
		return ArenaRules::replayFrames($state);
	}

	public function snapshot(string $matchId): array {
		$state = $this->matches->loadOwned($matchId, $this->access->requireActor());
		return ArenaRules::publicState($state);
	}

	public function humanCommand(string $matchId, int $revision, array $command): array {
		$actor = $this->access->requireActor();
		$state = $this->matches->loadOwned($matchId, $actor);
		$this->requireRevision($state, $revision);
		$next = ArenaRules::command($state, 'human', $command);
		$this->matches->replace($actor, $next, $revision);
		$next['revision'] = $revision + 1;
		return ArenaRules::publicState($next);
	}

	public function endHumanTurn(string $matchId, int $revision): array {
		$actor = $this->access->requireActor();
		$state = $this->matches->loadOwned($matchId, $actor);
		$this->requireRevision($state, $revision);
		$next = ArenaRules::completePhase($state, 'human');
		$this->matches->replace($actor, $next, $revision);
		$next['revision'] = $revision + 1;
		return ArenaRules::publicState($next);
	}

	/** Claims one AI phase with an opaque match-owned nonce. A timed-out claim may be superseded. */
	public function claimAi(string $matchId): string {
		$actor = $this->access->requireActor();
		$state = $this->matches->loadOwned($matchId, $actor);
		$stale = $state['phase'] === 'ai_running' &&
			is_int($state['ai_started_at'] ?? null) && $state['ai_started_at'] < time() - 300;
		if ($state['phase'] !== 'ai' && !$stale) { throw new RuntimeException('arena.invalid_phase'); }
		$nonce = bin2hex(random_bytes(24));
		$state['phase'] = 'ai_running';
		$state['ai_nonce'] = $nonce;
		$state['ai_started_at'] = time();
		$this->matches->replace($actor, $state, $state['revision']);
		return $nonce;
	}

	/** A tool may access the match only within the trusted AI execution claim. */
	public function toolSnapshot(string $matchId, string $nonce): array {
		$state = $this->requireAiClaim($matchId, $nonce);
		$public = ArenaRules::publicState($state);
		$public['tactical_briefing'] = ArenaRules::tacticalBriefing($state);
		return $public;
	}

	/** Applies the entire planned AI turn atomically. No partial or invalid turn is stored. */
	public function submitAiTurn(string $matchId, string $nonce, array $commands, string $radio = ''): array {
		$actor = $this->access->requireActor();
		$state = $this->matches->loadOwned($matchId, $actor);
		$this->assertClaim($state, $nonce);
		$revision = $state['revision'];
		if (count($commands) > 6) { throw new RuntimeException('arena.too_many_commands'); }
		foreach ($commands as $command) {
			if (!is_array($command)) { throw new RuntimeException('arena.invalid_action'); }
			$state = ArenaRules::command($state, 'ai', $command);
		}
		$state = ArenaRules::recordRadio($state, $radio);
		if ($state['winner'] === null) {
			$state = ArenaRules::completePhase($state, 'ai');
		} else {
			$state['ai_nonce'] = null;
			$state['ai_started_at'] = null;
		}
		$this->matches->replace($actor, $state, $revision);
		$state['revision'] = $revision + 1;
		return ArenaRules::publicState($state);
	}

	/** Restores an uncommitted AI phase after an execution failure, but never undoes a completed turn. */
	public function releaseAi(string $matchId, string $nonce): void {
		$actor = $this->access->requireActor();
		$state = $this->matches->loadOwned($matchId, $actor);
		if ($state['phase'] !== 'ai_running' || !hash_equals((string)$state['ai_nonce'], $nonce)) { return; }
		$state['phase'] = 'ai';
		$state['ai_nonce'] = null;
		$state['ai_started_at'] = null;
		$this->matches->replace($actor, $state, $state['revision']);
	}

	private function requireAiClaim(string $matchId, string $nonce): array {
		$state = $this->matches->loadOwned($matchId, $this->access->requireActor());
		$this->assertClaim($state, $nonce);
		return $state;
	}

	private function assertClaim(array $state, string $nonce): void {
		if ($nonce === '' || $state['phase'] !== 'ai_running' ||
			!is_string($state['ai_nonce'] ?? null) || !hash_equals($state['ai_nonce'], $nonce)) {
			throw new RuntimeException('arena.invalid_ai_claim');
		}
	}

	private function requireRevision(array $state, int $revision): void {
		if ($revision < 0 || $state['revision'] !== $revision) {
			throw new RuntimeException('arena.stale_state');
		}
	}
}
