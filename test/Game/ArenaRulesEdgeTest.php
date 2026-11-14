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
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ArenaRulesEdgeTest extends TestCase {

	private const ID = '0123456789abcdef0123456789abcdef';

	public function testMoveRejectsInvalidCoordinatesWallsUnitsAndOversizedPaths(): void {
		$state = ArenaRules::create(self::ID);
		$invalid = [
			['unit' => 'h_scout', 'type' => 'move', 'x' => 3, 'y' => 1],
			['unit' => 'h_scout', 'type' => 'move', 'x' => 8, 'y' => 3],
			['unit' => 'h_scout', 'type' => 'move', 'x' => -1, 'y' => 1],
			['unit' => 'h_scout', 'type' => 'move', 'x' => 1, 'y' => 7],
			['unit' => 'h_scout', 'type' => 'move', 'x' => '2', 'y' => 1],
			['unit' => 'h_scout', 'type' => 'move', 'x' => 0, 'y' => 3],
			['unit' => 'h_scout', 'type' => 'move', 'x' => 4, 'y' => 1]
		];
		foreach ($invalid as $command) {
			try {
				ArenaRules::command($state, 'human', $command);
				self::fail('Invalid move must be rejected.');
			} catch (InvalidArgumentException $error) {
				self::assertSame('arena.invalid_move', $error->getMessage());
			}
		}
		self::assertSame(0, $state['units']['h_scout']['x']);
	}

	public function testMoveOnceAndActOncePerRound(): void {
		$state = ArenaRules::create(self::ID);
		$state = ArenaRules::command($state, 'human', [
			'unit' => 'h_scout', 'type' => 'move', 'x' => 2, 'y' => 1
		]);
		$state = ArenaRules::command($state, 'human', ['unit' => 'h_scout', 'type' => 'guard']);
		self::assertFalse(ArenaRules::availableCommands($state)['h_scout']['guard']);
		foreach ([['unit' => 'h_scout', 'type' => 'move', 'x' => 2, 'y' => 2],
			['unit' => 'h_scout', 'type' => 'guard']] as $command) {
			try {
				ArenaRules::command($state, 'human', $command);
				self::fail('Unit must not act or move again.');
			} catch (InvalidArgumentException $error) {
				self::assertContains($error->getMessage(), ['arena.invalid_move', 'arena.invalid_action']);
			}
		}
		$ai = ArenaRules::completePhase($state, 'human');
		self::assertSame([], ArenaRules::availableCommands($ai));
		$ai['phase'] = 'ai_running';
		$next = ArenaRules::completePhase($ai, 'ai');
		self::assertSame('human', $next['phase']);
		self::assertFalse($next['units']['h_scout']['moved']);
		self::assertFalse($next['units']['h_scout']['acted']);
		self::assertTrue($next['units']['h_scout']['guard']);
	}

	public function testInvalidUnitPhaseAndUnsupportedActionAreRejected(): void {
		$state = ArenaRules::create(self::ID);
		$cases = [
			['ai', ['unit' => 'a_scout', 'type' => 'guard'], 'arena.invalid_phase'],
			['human', ['unit' => 'a_scout', 'type' => 'guard'], 'arena.invalid_unit'],
			['human', ['unit' => 'missing', 'type' => 'guard'], 'arena.invalid_unit'],
			['human', ['unit' => 42, 'type' => 'guard'], 'arena.invalid_unit'],
			['human', ['unit' => 'h_scout', 'type' => 'magic'], 'arena.invalid_action'],
			['alien', ['unit' => 'h_scout', 'type' => 'guard'], 'arena.invalid_phase']
		];
		foreach ($cases as [$team, $command, $expected]) {
			try {
				ArenaRules::command($state, $team, $command);
				self::fail('Invalid unit or phase must not execute.');
			} catch (InvalidArgumentException $error) {
				self::assertSame($expected, $error->getMessage());
			}
		}
		$state['units']['h_scout']['hp'] = 0;
		$this->expectExceptionMessage('arena.invalid_unit');
		ArenaRules::command($state, 'human', ['unit' => 'h_scout', 'type' => 'guard']);
	}

	public function testStraightLineAttackHonorsRangeObstaclesAndDefense(): void {
		$state = ArenaRules::create(self::ID);
		$state['units']['h_ranger']['x'] = 2;
		$state['units']['h_ranger']['y'] = 3;
		$state['units']['a_scout']['x'] = 5;
		$state['units']['a_scout']['y'] = 3;
		$state['units']['h_guardian']['x'] = 3;
		$state['units']['h_guardian']['y'] = 3;
		try {
			ArenaRules::command($state, 'human', ['unit' => 'h_ranger', 'type' => 'attack', 'target' => 'a_scout']);
			self::fail('A blocking unit must prevent a ranged attack.');
		} catch (InvalidArgumentException $error) {
			self::assertSame('arena.invalid_attack', $error->getMessage());
		}
		$state['units']['h_guardian']['x'] = 0;
		$state['units']['h_guardian']['y'] = 3;
		$state['units']['a_scout']['guard'] = true;
		$guarded = ArenaRules::command($state, 'human', ['unit' => 'h_ranger', 'type' => 'attack', 'target' => 'a_scout']);
		self::assertSame(5, $guarded['units']['a_scout']['hp']);
		self::assertFalse($guarded['units']['a_scout']['guard']);
		self::assertSame(0, end($guarded['events'])['damage']);
		self::assertTrue($guarded['units']['h_ranger']['acted']);
		self::assertSame(1, ArenaRules::tacticalBriefing($guarded)['human_attack_targets']['a_scout']);
	}

	public function testWallsDiagonalAndRangeBlockAttacks(): void {
		$state = ArenaRules::create(self::ID);
		$state['units']['h_ranger']['x'] = 3;
		$state['units']['h_ranger']['y'] = 0;
		$state['units']['a_scout']['x'] = 3;
		$state['units']['a_scout']['y'] = 2;
		$attack = ['unit' => 'h_ranger', 'type' => 'attack', 'target' => 'a_scout'];
		foreach ([[3, 2], [4, 2], [8, 0]] as [$x, $y]) {
			$state['units']['a_scout']['x'] = $x;
			$state['units']['a_scout']['y'] = $y;
			try { ArenaRules::command($state, 'human', $attack); self::fail('Attack must be blocked.'); }
			catch (InvalidArgumentException $error) { self::assertSame('arena.invalid_attack', $error->getMessage()); }
		}
	}

	public function testScoreVictoryAndHealthTieBreakAreDeterministic(): void {
		$state = ArenaRules::create(self::ID);
		$state['phase'] = 'ai_running';
		$state['scores']['human'] = 7;
		$state['units']['h_scout']['x'] = 2;
		$state['units']['h_scout']['y'] = 3;
		$state = ArenaRules::completePhase($state, 'ai');
		self::assertSame('human', $state['winner']);
		self::assertSame('finished', $state['phase']);
		self::assertSame(8, $state['scores']['human']);
		$states = [
			['hpHuman' => 19, 'hpAi' => 18, 'winner' => 'human'],
			['hpHuman' => 18, 'hpAi' => 19, 'winner' => 'ai'],
			['hpHuman' => 20, 'hpAi' => 20, 'winner' => 'draw']
		];
		foreach ($states as $case) {
			$state = ArenaRules::create(self::ID);
			$state['round'] = 10;
			$state['phase'] = 'ai_running';
			$state['scores'] = ['human' => 3, 'ai' => 3];
			$state['units']['h_scout']['hp'] = $case['hpHuman'] - 15;
			$state['units']['a_scout']['hp'] = $case['hpAi'] - 15;
			$finished = ArenaRules::completePhase($state, 'ai');
			self::assertSame($case['winner'], $finished['winner']);
		}
	}

	public function testRadioRejectsMalformedOverlongAndControlMessages(): void {
		$state = ArenaRules::create(self::ID);
		foreach ([str_repeat('a', 161), "Invalid\nmessage", "\xFF"] as $text) {
			try { ArenaRules::recordRadio($state, $text); self::fail('Invalid radio must be rejected.'); }
			catch (InvalidArgumentException $error) { self::assertSame('arena.invalid_radio', $error->getMessage()); }
		}
		$valid = ArenaRules::recordRadio($state, ' ' . str_repeat('a', 160) . ' ');
		self::assertSame(str_repeat('a', 160), end($valid['events'])['text']);
	}

	public function testTacticalBriefingFiltersHistoryAndNeverInventsUnknownTeams(): void {
		$state = ArenaRules::create(self::ID);
		$state['events'] = [['key' => 'match_started']];
		for ($i = 0; $i < 20; $i++) {
			$state['events'][] = ['key' => 'unit_attacked', 'unit' => 'h_scout', 'target' => 'a_guardian'];
		}
		for ($i = 0; $i < 14; $i++) {
			$state['events'][] = ['key' => 'unit_guarded', 'unit' => 'a_guardian'];
		}
		$state['events'][] = ['key' => 'unit_guarded', 'unit' => 'mystery'];
		$state['units']['h_scout']['x'] = 2;
		$state['units']['h_scout']['y'] = 3;
		$briefing = ArenaRules::tacticalBriefing($state);
		self::assertSame('human', $briefing['control_occupancy'][0]['team']);
		self::assertCount(16, $briefing['recent_human_actions']);
		self::assertCount(12, $briefing['recent_ai_actions']);
		self::assertSame(20, $briefing['human_attack_targets']['a_guardian']);
	}

	public function testEventCountIsBoundedWithoutErasingLatestAction(): void {
		$state = ArenaRules::create(self::ID);
		$state['events'] = array_fill(0, 256, ['key' => 'old']);
		$state = ArenaRules::command($state, 'human', ['unit' => 'h_scout', 'type' => 'guard']);
		self::assertCount(256, $state['events']);
		self::assertSame('unit_guarded', end($state['events'])['key']);
	}

	public function testReplayRejectsTamperedEventLogAndOrdering(): void {
		$finished = $this->finished();
		$variants = [];
		$replaced = $finished;
		$replaced['events'][0]['key'] = 'wrong';
		$variants[] = $replaced;
		$replaced = $finished;
		$replaced['events'][] = ['key' => 'unexpected'];
		$variants[] = $replaced;
		$replaced = $finished;
		$replaced['events'][1] = 'invalid';
		$variants[] = $replaced;
		$replaced = $finished;
		$replaced['events'] = ['not-a-list' => ['key' => 'match_started']];
		$variants[] = $replaced;
		$replaced = $finished;
		$replaced['events'] = array_fill(0, 257, ['key' => 'match_started']);
		$variants[] = $replaced;
		foreach ($variants as $variant) {
			try { ArenaRules::replayFrames($variant); self::fail('Tampered log must not replay.'); }
			catch (InvalidArgumentException $error) { self::assertSame('arena.replay_unavailable', $error->getMessage()); }
		}
	}

	private function finished(): array {
		$state = ArenaRules::create(self::ID);
		for ($i = 0; $i < 10; $i++) {
			$state = ArenaRules::completePhase($state, 'human');
			$state['phase'] = 'ai_running';
			$state = ArenaRules::completePhase($state, 'ai');
		}
		return $state;
	}
}
