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


namespace Base3\AgentArena\Test\Game;

use Base3\AgentArena\Game\ArenaRules;
use PHPUnit\Framework\TestCase;

/** All three maps and deterministic match replay use the normal Arena rules. */
final class ArenaReplayTest extends TestCase {

	public function testMapDefinitionsArePlayableAndSymmetric(): void {
		self::assertSame(['standard', 'bastion', 'crossfire'], array_keys(ArenaRules::maps()));
		foreach (ArenaRules::maps() as $mapId => $map) {
			$state = ArenaRules::create(str_repeat('a', 32), $mapId);
			self::assertSame($mapId, $state['map_id']);
			self::assertCount(3, $state['points']);
			self::assertCount(6, $state['units']);
			self::assertNotEmpty(ArenaRules::availableCommands($state)['h_scout']['moves']);
			self::assertSame(ArenaRules::publicState($state)['map_id'], $mapId);
			foreach ($map['walls'] as $wall) {
				self::assertContains([8 - $wall[0], $wall[1]], $map['walls']);
				self::assertNotContains($wall, $map['points']);
			}
			foreach ($state['units'] as $unit) {
				self::assertNotContains([$unit['x'], $unit['y']], $map['walls']);
			}
		}
	}

	public function testUnknownMapIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		ArenaRules::create(str_repeat('b', 32), 'not-a-map');
	}

	public function testCompleteReplayMatchesGameOnAllMaps(): void {
		foreach (array_keys(ArenaRules::maps()) as $mapId) {
			$state = $this->completedGame($mapId);
			$frames = ArenaRules::replayFrames($state);
			self::assertCount(count($state['events']), $frames);
			self::assertSame('match_started', $frames[0]['event']['key']);
			$end = end($frames)['state'];
			foreach (['map_id', 'round', 'winner', 'phase', 'units', 'points', 'walls', 'scores'] as $field) {
				self::assertSame($state[$field], $end[$field]);
			}
			self::assertArrayNotHasKey('ai_nonce', $end);
			self::assertArrayNotHasKey('events', $end);
			self::assertArrayNotHasKey('options', $end);
		}
	}

	public function testReplayPreservesMixedActionsAndConsumedGuard(): void {
		$state = ArenaRules::create(str_repeat('9', 32));
		$state = ArenaRules::command($state, 'human', ['unit' => 'h_scout', 'type' => 'move', 'x' => 2, 'y' => 1]);
		$state = ArenaRules::command($state, 'human', ['unit' => 'h_scout', 'type' => 'guard']);
		$state = ArenaRules::completePhase($state, 'human');
		$state['phase'] = 'ai_running';
		$state = ArenaRules::command($state, 'ai', ['unit' => 'a_scout', 'type' => 'move', 'x' => 6, 'y' => 1]);
		$state = ArenaRules::command($state, 'ai', ['unit' => 'a_scout', 'type' => 'guard']);
		$state = ArenaRules::recordRadio($state, 'Schild aktiviert.');
		$state = ArenaRules::completePhase($state, 'ai');
		$state = ArenaRules::command($state, 'human', ['unit' => 'h_scout', 'type' => 'move', 'x' => 3, 'y' => 2]);
		$state = ArenaRules::completePhase($state, 'human');
		$state['phase'] = 'ai_running';
		$state = ArenaRules::command($state, 'ai', ['unit' => 'a_scout', 'type' => 'move', 'x' => 5, 'y' => 2]);
		$state = ArenaRules::completePhase($state, 'ai');
		$state = ArenaRules::command($state, 'human', ['unit' => 'h_scout', 'type' => 'move', 'x' => 4, 'y' => 2]);
		$state = ArenaRules::command($state, 'human', ['unit' => 'h_scout', 'type' => 'attack', 'target' => 'a_scout']);
		$state = ArenaRules::completePhase($state, 'human');
		$state['phase'] = 'ai_running';
		$state = ArenaRules::command($state, 'ai', ['unit' => 'a_scout', 'type' => 'attack', 'target' => 'h_scout']);
		$state = ArenaRules::completePhase($state, 'ai');
		while ($state['winner'] === null) {
			$state = ArenaRules::completePhase($state, 'human');
			$state['phase'] = 'ai_running';
			$state = ArenaRules::completePhase($state, 'ai');
		}
		$frames = ArenaRules::replayFrames($state);
		self::assertSame($state['units'], end($frames)['state']['units']);
		$attackFrames = array_values(array_filter($frames,
			static fn(array $frame): bool => $frame['event']['key'] === 'unit_attacked'));
		self::assertCount(2, $attackFrames);
		self::assertSame(0, $attackFrames[0]['event']['damage']);
		self::assertSame(0, $attackFrames[1]['event']['damage']);
	}

	public function testLegacyMatchWithoutMapIdReplaysOnStandardMap(): void {
		$state = $this->completedGame('standard');
		unset($state['map_id']);
		$frames = ArenaRules::replayFrames($state);
		self::assertSame('standard', end($frames)['state']['map_id']);
	}

	public function testReplayDoesNotModifySavedMatch(): void {
		$state = $this->completedGame('standard');
		$before = serialize($state);
		ArenaRules::replayFrames($state);
		self::assertSame($before, serialize($state));
	}

	public function testIncompleteMatchCannotBeReplayed(): void {
		$this->expectException(\InvalidArgumentException::class);
		ArenaRules::replayFrames(ArenaRules::create(str_repeat('c', 32)));
	}

	public function testMissingInitialEventCannotBeReplayed(): void {
		$state = $this->completedGame('standard');
		array_shift($state['events']);
		$this->expectException(\InvalidArgumentException::class);
		ArenaRules::replayFrames($state);
	}

	public function testTamperedMatchCannotBeReplayed(): void {
		$state = $this->completedGame('standard');
		$state['scores']['ai']++;
		$this->expectException(\InvalidArgumentException::class);
		ArenaRules::replayFrames($state);
	}

	private function completedGame(string $mapId): array {
		$state = ArenaRules::create(str_repeat('e', 32), $mapId);
		$state = ArenaRules::command($state, 'human', [
			'unit' => 'h_scout', 'type' => 'move', 'x' => 1, 'y' => 1
		]);
		$state = ArenaRules::completePhase($state, 'human');
		$state['phase'] = 'ai_running';
		$state = ArenaRules::recordRadio($state, 'Ich sichere die Stellung.');
		$state = ArenaRules::completePhase($state, 'ai');
		while ($state['winner'] === null) {
			$state = ArenaRules::completePhase($state, 'human');
			$state['phase'] = 'ai_running';
			$state = ArenaRules::completePhase($state, 'ai');
		}
		return $state;
	}
}
