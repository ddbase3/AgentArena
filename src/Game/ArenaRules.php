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


namespace Base3\AgentArena\Game;

use InvalidArgumentException;

/** Deterministic, side-effect-free Arena rules. The LLM never decides validity or scoring. */
final class ArenaRules {

	public const WIDTH = 9;
	public const HEIGHT = 7;
	private const MAX_EVENTS = 256;

	/** Map definitions are stable across releases so saved matches remain reproducible. */
	private const MAPS = [
		'standard' => [
			'points' => [[2, 3], [4, 3], [6, 3]],
			'walls' => [[3, 1], [3, 5], [5, 1], [5, 5]]
		],
		'bastion' => [
			'points' => [[2, 3], [4, 3], [6, 3]],
			'walls' => [[2, 2], [2, 4], [6, 2], [6, 4], [4, 1], [4, 5]]
		],
		'crossfire' => [
			'points' => [[2, 2], [4, 3], [6, 2]],
			'walls' => [[4, 0], [4, 6], [3, 5], [5, 5]]
		]
	];

	private const TYPES = [
		'scout' => ['hp' => 5, 'move' => 3, 'range' => 1, 'damage' => 2],
		'ranger' => ['hp' => 6, 'move' => 2, 'range' => 3, 'damage' => 2],
		'guardian' => ['hp' => 9, 'move' => 2, 'range' => 1, 'damage' => 3]
	];

	/** @return array<string,mixed> */
	public static function create(string $id, string $mapId = 'standard'): array {
		if (!isset(self::MAPS[$mapId])) { throw new InvalidArgumentException('arena.invalid_map'); }
		$units = [];
		foreach (['human' => 0, 'ai' => 8] as $team => $x) {
			foreach (['scout' => 1, 'guardian' => 3, 'ranger' => 5] as $type => $y) {
				$code = $team === 'human' ? 'h' : 'a';
				$units[$code . '_' . $type] = [
					'id' => $code . '_' . $type,
					'team' => $team,
					'type' => $type,
					'x' => $x,
					'y' => $y,
					'hp' => self::TYPES[$type]['hp'],
					'max_hp' => self::TYPES[$type]['hp'],
					'moved' => false,
					'acted' => false,
					'guard' => false
				];
			}
		}
		return [
			'id' => $id,
			'revision' => 0,
			'map_id' => $mapId,
			'phase' => 'human',
			'round' => 1,
			'limit' => 10,
			'scores' => ['human' => 0, 'ai' => 0],
			'points' => self::MAPS[$mapId]['points'],
			'walls' => self::MAPS[$mapId]['walls'],
			'units' => $units,
			'events' => [['key' => 'match_started', 'round' => 1]],
			'winner' => null,
			'ai_nonce' => null,
			'ai_started_at' => null
		];
	}

	/** Public, immutable map catalog. Localization stays in the display translations. */
	public static function maps(): array {
		return self::MAPS;
	}

	/**
	 * Reconstructs a complete match only from its authoritative event log, using
	 * the same rules as normal play. Frames are read-only derived responses.
	 * Incomplete/truncated or inconsistent logs are rejected, never guessed.
	 *
	 * @return array<int,array{event:array<string,mixed>,state:array<string,mixed>}>
	 */
	public static function replayFrames(array $saved): array {
		if (($saved['phase'] ?? null) !== 'finished' || ($saved['winner'] ?? null) === null) {
			throw new InvalidArgumentException('arena.replay_unavailable');
		}
		$events = $saved['events'] ?? null;
		if (!is_array($events) || !array_is_list($events) ||
			($events[0]['key'] ?? null) !== 'match_started' ||
			count($events) > self::MAX_EVENTS) {
			throw new InvalidArgumentException('arena.replay_unavailable');
		}
		$initial = self::create((string)$saved['id'], (string)($saved['map_id'] ?? 'standard'));
		$state = $initial;
		$frames = [self::replayFrame($state, $events[0])];
		foreach (array_slice($events, 1) as $event) {
			if (!is_array($event)) { throw new InvalidArgumentException('arena.replay_unavailable'); }
			$key = $event['key'] ?? null;
			$team = $event['team'] ?? null;
			if (in_array($key, ['unit_moved', 'unit_attacked', 'unit_guarded'], true)) {
				if (!in_array($team, ['human', 'ai'], true)) {
					throw new InvalidArgumentException('arena.replay_unavailable');
				}
				if ($team === 'ai' && $state['phase'] === 'ai') { $state['phase'] = 'ai_running'; }
				$command = match($key) {
					'unit_moved' => ['type' => 'move', 'unit' => $event['unit'] ?? null,
						'x' => $event['x'] ?? null, 'y' => $event['y'] ?? null],
					'unit_attacked' => ['type' => 'attack', 'unit' => $event['unit'] ?? null,
						'target' => $event['target'] ?? null],
					default => ['type' => 'guard', 'unit' => $event['unit'] ?? null]
				};
				$state = self::command($state, $team, $command);
				if (end($state['events']) !== $event) {
					throw new InvalidArgumentException('arena.replay_unavailable');
				}
			} elseif ($key === 'human_turn_ended') {
				$state = self::completePhase($state, 'human');
			} elseif ($key === 'nexus_radio') {
				$state = self::recordRadio($state, (string)($event['text'] ?? ''));
			} elseif ($key === 'round_completed') {
				if ($state['phase'] === 'ai') { $state['phase'] = 'ai_running'; }
				$state = self::completePhase($state, 'ai');
			} else {
				throw new InvalidArgumentException('arena.replay_unavailable');
			}
			if (end($state['events']) !== $event) {
				throw new InvalidArgumentException('arena.replay_unavailable');
			}
			$frames[] = self::replayFrame($state, $event);
		}
		foreach (['round', 'phase', 'scores', 'units', 'winner', 'points', 'walls'] as $field) {
			if (($state[$field] ?? null) !== ($saved[$field] ?? null)) {
				throw new InvalidArgumentException('arena.replay_unavailable');
			}
		}
		return $frames;
	}

	/** @return array{event:array<string,mixed>,state:array<string,mixed>} */
	private static function replayFrame(array $state, array $event): array {
		$view = self::publicState($state, false);
		unset($view['events']);
		return ['event' => $event, 'state' => $view];
	}

	/** Applies one player or agent command, refusing all invalid moves before any persistence occurs. */
	public static function command(array $state, string $team, array $command): array {
		if (($state['winner'] ?? null) !== null ||
			!in_array($team, ['human', 'ai'], true) ||
			($state['phase'] ?? '') !== ($team === 'human' ? 'human' : 'ai_running')) {
			throw new InvalidArgumentException('arena.invalid_phase');
		}
		$unitId = $command['unit'] ?? null;
		$type = $command['type'] ?? null;
		if (!is_string($unitId) || !is_string($type) || !isset($state['units'][$unitId])) {
			throw new InvalidArgumentException('arena.invalid_unit');
		}
		$unit = $state['units'][$unitId];
		if ($unit['team'] !== $team || $unit['hp'] <= 0) {
			throw new InvalidArgumentException('arena.invalid_unit');
		}
		if ($type === 'move') {
			if ($unit['moved'] || $unit['acted'] || !is_int($command['x'] ?? null) || !is_int($command['y'] ?? null)) {
				throw new InvalidArgumentException('arena.invalid_move');
			}
			$x = $command['x']; $y = $command['y'];
			if (!self::reachable($state, $unit, $x, $y)) {
				throw new InvalidArgumentException('arena.invalid_move');
			}
			$state['units'][$unitId]['x'] = $x;
			$state['units'][$unitId]['y'] = $y;
			$state['units'][$unitId]['moved'] = true;
			$state['events'][] = ['key' => 'unit_moved', 'round' => $state['round'], 'team' => $team,
				'unit' => $unitId, 'x' => $x, 'y' => $y];
		} elseif ($type === 'attack') {
			$targetId = $command['target'] ?? null;
			if ($unit['acted'] || !is_string($targetId) || !isset($state['units'][$targetId])) {
				throw new InvalidArgumentException('arena.invalid_attack');
			}
			$target = $state['units'][$targetId];
			if ($target['team'] === $team || $target['hp'] <= 0 || !self::inRange($state, $unit, $target)) {
				throw new InvalidArgumentException('arena.invalid_attack');
			}
			$damage = self::TYPES[$unit['type']]['damage'];
			if ($target['guard']) { $damage = max(0, $damage - 2); }
			$state['units'][$targetId]['hp'] = max(0, $target['hp'] - $damage);
			$state['units'][$targetId]['guard'] = false;
			$state['units'][$unitId]['acted'] = true;
			$state['events'][] = ['key' => 'unit_attacked', 'round' => $state['round'], 'team' => $team,
				'unit' => $unitId, 'target' => $targetId, 'damage' => $damage];
			self::checkElimination($state);
		} elseif ($type === 'guard') {
			if ($unit['acted']) { throw new InvalidArgumentException('arena.invalid_action'); }
			$state['units'][$unitId]['acted'] = true;
			$state['units'][$unitId]['guard'] = true;
			$state['events'][] = ['key' => 'unit_guarded', 'round' => $state['round'], 'team' => $team, 'unit' => $unitId];
		} else {
			throw new InvalidArgumentException('arena.invalid_action');
		}
		$state['events'] = array_slice($state['events'], -self::MAX_EVENTS);
		return $state;
	}

	/** Completes a team phase, scores controlled cells once after AI, then checks victory. */
	public static function completePhase(array $state, string $team): array {
		if (($state['winner'] ?? null) !== null ||
			($state['phase'] ?? '') !== ($team === 'human' ? 'human' : 'ai_running')) {
			throw new InvalidArgumentException('arena.invalid_phase');
		}
		if ($team === 'human') {
			$state['phase'] = 'ai';
			$state['events'][] = ['key' => 'human_turn_ended', 'round' => $state['round']];
			return $state;
		}

		foreach ($state['points'] as [$x, $y]) {
			foreach ($state['units'] as $unit) {
				if ($unit['hp'] > 0 && $unit['x'] === $x && $unit['y'] === $y) {
					$state['scores'][$unit['team']]++;
					break;
				}
			}
		}
		$state['events'][] = ['key' => 'round_completed', 'round' => $state['round']];
		$state['ai_nonce'] = null;
		$state['ai_started_at'] = null;
		if ($state['scores']['human'] >= 8 || $state['scores']['ai'] >= 8 || $state['round'] >= $state['limit']) {
			self::finishByScore($state);
		} else {
			$state['round']++;
			$state['phase'] = 'human';
			foreach ($state['units'] as &$unit) { $unit['moved'] = false; $unit['acted'] = false; }
			unset($unit);
		}
		return $state;
	}


	/**
	 * Adds one short, explicitly untrusted NEXUS radio message to the authoritative
	 * match event stream. A match stores it with the validated AI move, not in a
	 * second conversation or narrative store. Only the service may invoke this.
	 */
	public static function recordRadio(array $state, string $message): array {
		$message = trim($message);
		if ($message === '') { return $state; }
		$length = preg_match_all('/./us', $message);
		if ($length === false || $length > 160 || preg_match('/\p{C}/u', $message) !== 0) {
			throw new InvalidArgumentException('arena.invalid_radio');
		}
		$state['events'][] = [
			'key' => 'nexus_radio',
			'round' => $state['round'],
			'team' => 'ai',
			'text' => $message
		];
		$state['events'] = array_slice($state['events'], -self::MAX_EVENTS);
		return $state;
	}

	/**
	 * Read-only tactical facts derived from the authoritative state and its event
	 * log, never from inferred memory, an extra repository, or model output.
	 * Old match events may have no round/team markers; never fabricate them.
	 *
	 * @return array<string,mixed>
	 */
	public static function tacticalBriefing(array $state): array {
		$control = [];
		foreach ($state['points'] as [$x, $y]) {
			$owner = null;
			$occupant = null;
			foreach ($state['units'] as $unit) {
				if ($unit['hp'] > 0 && $unit['x'] === $x && $unit['y'] === $y) {
					$owner = $unit['team'];
					$occupant = $unit['id'];
					break;
				}
			}
			$control[] = ['x' => $x, 'y' => $y, 'team' => $owner, 'unit' => $occupant];
		}

		$humanActions = [];
		$aiActions = [];
		$humanAttackTargets = [];
		foreach ($state['events'] as $event) {
			if (!is_array($event) || !in_array($event['key'] ?? null,
				['unit_moved', 'unit_attacked', 'unit_guarded'], true)) {
				continue;
			}
			$unit = (string)($event['unit'] ?? '');
			$team = $event['team'] ?? (str_starts_with($unit, 'h_') ? 'human' :
				(str_starts_with($unit, 'a_') ? 'ai' : null));
			if ($team === 'human') {
				$humanActions[] = $event;
				if (($event['key'] ?? '') === 'unit_attacked' && is_string($event['target'] ?? null)) {
					$target = $event['target'];
					$humanAttackTargets[$target] = ($humanAttackTargets[$target] ?? 0) + 1;
				}
			} elseif ($team === 'ai') {
				$aiActions[] = $event;
			}
		}
		arsort($humanAttackTargets);
		return [
			'control_occupancy' => $control,
			'recent_human_actions' => array_slice($humanActions, -16),
			'recent_ai_actions' => array_slice($aiActions, -12),
			'human_attack_targets' => $humanAttackTargets,
			'note' => 'All values are observed past events and current positions. They are not predictions.'
		];
	}

	/** Computes reachable tiles with terrain, units and orthogonal shortest-path movement. */
	private static function reachable(array $state, array $unit, int $goalX, int $goalY): bool {
		if (!self::inside($goalX, $goalY) || self::blocked($state, $goalX, $goalY)) { return false; }
		$max = self::TYPES[$unit['type']]['move'];
		$queue = [[$unit['x'], $unit['y'], 0]];
		$visited = [$unit['x'] . ':' . $unit['y'] => true];
		for ($head = 0; $head < count($queue); $head++) {
			[$x, $y, $distance] = $queue[$head];
			if ($distance >= $max) { continue; }
			foreach ([[0,1],[1,0],[0,-1],[-1,0]] as [$dx,$dy]) {
				$nx = $x + $dx; $ny = $y + $dy;
				$key = $nx . ':' . $ny;
				if (!self::inside($nx,$ny) || isset($visited[$key]) || self::blocked($state,$nx,$ny)) { continue; }
				if ($nx === $goalX && $ny === $goalY) { return true; }
				$visited[$key] = true;
				$queue[] = [$nx,$ny,$distance+1];
			}
		}
		return false;
	}

	private static function inside(int $x, int $y): bool {
		return $x >= 0 && $x < self::WIDTH && $y >= 0 && $y < self::HEIGHT;
	}

	private static function blocked(array $state, int $x, int $y): bool {
		foreach ($state['walls'] as $wall) { if ($wall[0] === $x && $wall[1] === $y) { return true; } }
		foreach ($state['units'] as $other) {
			if ($other['hp'] > 0 && $other['x'] === $x && $other['y'] === $y) { return true; }
		}
		return false;
	}

	private static function inRange(array $state, array $attacker, array $target): bool {
		$dx = $target['x'] - $attacker['x'];
		$dy = $target['y'] - $attacker['y'];
		$distance = abs($dx) + abs($dy);
		if ($distance === 0 || $distance > self::TYPES[$attacker['type']]['range'] || ($dx !== 0 && $dy !== 0)) {
			return false;
		}
		$stepX = $dx <=> 0; $stepY = $dy <=> 0;
		for ($step = 1; $step < $distance; $step++) {
			if (self::blocked($state, $attacker['x'] + $step * $stepX, $attacker['y'] + $step * $stepY)) { return false; }
		}
		return true;
	}

	private static function checkElimination(array &$state): void {
		$alive = ['human' => 0, 'ai' => 0];
		foreach ($state['units'] as $unit) { if ($unit['hp'] > 0) { $alive[$unit['team']]++; } }
		if ($alive['human'] === 0) { $state['winner'] = 'ai'; $state['phase'] = 'finished'; }
		if ($alive['ai'] === 0) { $state['winner'] = 'human'; $state['phase'] = 'finished'; }
	}

	private static function finishByScore(array &$state): void {
		$human = $state['scores']['human']; $ai = $state['scores']['ai'];
		if ($human === $ai) {
			foreach ($state['units'] as $unit) {
				if ($unit['team'] === 'human') { $human += $unit['hp'] / 100; }
				else { $ai += $unit['hp'] / 100; }
			}
		}
		$state['winner'] = $human === $ai ? 'draw' : ($human > $ai ? 'human' : 'ai');
		$state['phase'] = 'finished';
	}

	/** Shows authoritative legal actions without maintaining a second client-side rules engine. */
	public static function availableCommands(array $state): array {
		$team = match($state['phase']) { 'human' => 'human', 'ai_running' => 'ai', default => null };
		if ($team === null || $state['winner'] !== null) { return []; }
		$options = [];
		foreach ($state['units'] as $id => $unit) {
			if ($unit['team'] !== $team || $unit['hp'] <= 0) { continue; }
			$moves = []; $targets = [];
			if (!$unit['moved'] && !$unit['acted']) {
				for ($y = 0; $y < self::HEIGHT; $y++) {
					for ($x = 0; $x < self::WIDTH; $x++) {
						if (self::reachable($state, $unit, $x, $y)) { $moves[] = [$x, $y]; }
					}
				}
			}
			if (!$unit['acted']) {
				foreach ($state['units'] as $otherId => $other) {
					if ($other['team'] !== $team && $other['hp'] > 0 && self::inRange($state, $unit, $other)) {
						$targets[] = $otherId;
					}
				}
			}
			$options[$id] = ['moves' => $moves, 'targets' => $targets, 'guard' => !$unit['acted']];
		}
		return $options;
	}

	/** Removes internal execution credentials before returning a snapshot to clients or tools. */
	public static function publicState(array $state, bool $includeOptions = true): array {
		unset($state['ai_nonce'], $state['ai_started_at']);
		$state['map_id'] = $state['map_id'] ?? 'standard';
		if ($includeOptions) { $state['options'] = self::availableCommands($state); }
		else { unset($state['options']); }
		return $state;
	}
}
