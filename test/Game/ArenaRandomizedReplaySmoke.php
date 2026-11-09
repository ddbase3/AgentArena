<?php declare(strict_types=1);

namespace AgentArena\Test\Game;

use AgentArena\Game\ArenaRules;
use RuntimeException;

/** Replays deterministic full matches on all maps, including mixed legal action sequences. */
final class ArenaRandomizedReplaySmoke {

	public static function run(): void {
		mt_srand(7301);
		foreach (array_keys(ArenaRules::maps()) as $mapId) {
			for ($attempt = 0; $attempt < 5; $attempt++) {
				$state = ArenaRules::create(bin2hex(random_bytes(16)), $mapId);
				while ($state['winner'] === null) {
					foreach (['human', 'ai'] as $team) {
						if ($state['winner'] !== null) { break; }
						if ($team === 'ai') { $state['phase'] = 'ai_running'; }
						foreach (['scout', 'guardian', 'ranger'] as $type) {
							if ($state['winner'] !== null) { break; }
							$id = ($team === 'human' ? 'h' : 'a') . '_' . $type;
							$options = ArenaRules::availableCommands($state)[$id] ?? null;
							if ($options === null) { continue; }
							if ($options['moves'] && mt_rand(0, 3) !== 0) {
								$position = $options['moves'][mt_rand(0, count($options['moves']) - 1)];
								$state = ArenaRules::command($state, $team, [
									'unit' => $id, 'type' => 'move', 'x' => $position[0], 'y' => $position[1]
								]);
							}
							$options = ArenaRules::availableCommands($state)[$id] ?? null;
							if ($options && $options['targets'] && mt_rand(0, 2) !== 0) {
								$target = $options['targets'][mt_rand(0, count($options['targets']) - 1)];
								$state = ArenaRules::command($state, $team, [
									'unit' => $id, 'type' => 'attack', 'target' => $target
								]);
							} elseif ($options && $options['guard'] && mt_rand(0, 2) !== 0) {
								$state = ArenaRules::command($state, $team, ['unit' => $id, 'type' => 'guard']);
							}
						}
						if ($state['winner'] === null) {
							if ($team === 'ai' && mt_rand(0, 2) === 0) {
								$state = ArenaRules::recordRadio($state, 'Plan ausgefuehrt.');
							}
							$state = ArenaRules::completePhase($state, $team);
						}
					}
				}
				$frames = ArenaRules::replayFrames($state);
				if (count($frames) !== count($state['events'])) {
					throw new RuntimeException('Replay event count mismatch: ' . $mapId);
				}
				$last = end($frames)['state'];
				foreach (['round', 'scores', 'winner', 'phase', 'units', 'map_id'] as $field) {
					if ($last[$field] !== $state[$field]) {
						throw new RuntimeException('Replay differs: ' . $mapId . '/' . $field);
					}
				}
			}
		}
	}
}
