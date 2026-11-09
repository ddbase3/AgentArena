<?php declare(strict_types=1);

namespace AgentArena\Test\Game;

use AgentArena\Game\ArenaRules;
use PHPUnit\Framework\TestCase;

/** Deterministic rule coverage with no model call, database or host requirements. */
final class ArenaRulesTest extends TestCase {

	public function testInitialBoardAndActions(): void {
		$state = ArenaRules::create(str_repeat('a', 32));
		self::assertSame(6, count($state['units']));
		self::assertSame('human', $state['phase']);
		self::assertContains([2, 1], ArenaRules::availableCommands($state)['h_scout']['moves']);
		$next = ArenaRules::command($state, 'human', ['unit' => 'h_scout', 'type' => 'move', 'x' => 2, 'y' => 1]);
		self::assertTrue($next['units']['h_scout']['moved']);
		self::assertSame(0, $state['units']['h_scout']['x']);
	}

	public function testRejectsOutOfTurnAndIllegalMove(): void {
		$state = ArenaRules::create(str_repeat('b', 32));
		$this->expectException(\InvalidArgumentException::class);
		ArenaRules::command($state, 'ai', ['unit' => 'a_scout', 'type' => 'guard']);
	}

	public function testBlockedMovementAndRange(): void {
		$state = ArenaRules::create(str_repeat('c', 32));
		self::assertNotContains([3, 1], ArenaRules::availableCommands($state)['h_scout']['moves']);
		$this->expectException(\InvalidArgumentException::class);
		ArenaRules::command($state, 'human', ['unit' => 'h_ranger', 'type' => 'attack', 'target' => 'a_guardian']);
	}

	public function testFullRoundScoresControlPoint(): void {
		$state = ArenaRules::create(str_repeat('d', 32));
		$state = ArenaRules::completePhase($state, 'human');
		$state['phase'] = 'ai_running';
		$state = ArenaRules::command($state, 'ai', ['unit' => 'a_guardian', 'type' => 'move', 'x' => 6, 'y' => 3]);
		$state = ArenaRules::command($state, 'ai', ['unit' => 'a_guardian', 'type' => 'guard']);
		$state = ArenaRules::completePhase($state, 'ai');
		self::assertSame(1, $state['scores']['ai']);
		self::assertSame(2, $state['round']);
		self::assertSame('human', $state['phase']);
	}

	public function testHitConsumesGuardAndEliminationEndsMatch(): void {
		$state = ArenaRules::create(str_repeat('e', 32));
		$state['units']['h_scout']['x'] = 7; $state['units']['h_scout']['y'] = 3;
		$state['units']['h_scout']['hp'] = 2;
		$state['units']['h_guardian']['hp'] = 0;
		$state['units']['h_ranger']['hp'] = 0;
		$state['phase'] = 'ai_running';
		$state = ArenaRules::command($state, 'ai', ['unit' => 'a_guardian', 'type' => 'attack', 'target' => 'h_scout']);
		self::assertSame('ai', $state['winner']);
		self::assertSame('finished', $state['phase']);
	}

	public function testTacticalBriefingUsesOnlyObservedActions(): void {
		$state = ArenaRules::create(str_repeat('7', 32));
		$state = ArenaRules::command($state, 'human', [
			'unit' => 'h_scout', 'type' => 'move', 'x' => 2, 'y' => 1
		]);
		$briefing = ArenaRules::tacticalBriefing($state);
		self::assertCount(3, $briefing['control_occupancy']);
		self::assertSame('h_scout', $briefing['recent_human_actions'][0]['unit']);
		self::assertSame(1, $briefing['recent_human_actions'][0]['round']);
		self::assertSame([], $briefing['human_attack_targets']);
	}

	public function testRadioPersistsOnlyAfterValidation(): void {
		$state = ArenaRules::create(str_repeat('8', 32));
		$updated = ArenaRules::recordRadio($state, 'NEXUS: Mitte gesichert.');
		self::assertCount(count($state['events']) + 1, $updated['events']);
		self::assertSame('NEXUS: Mitte gesichert.', end($updated['events'])['text']);
		self::assertSame($state, ArenaRules::recordRadio($state, ''));
	}

	public function testRadioRejectsControlCharacters(): void {
		$this->expectException(\InvalidArgumentException::class);
		ArenaRules::recordRadio(ArenaRules::create(str_repeat('9', 32)), "No\nthanks");
	}

	public function testCredentialsNotExposed(): void {
		$state = ArenaRules::create(str_repeat('f', 32));
		$state['ai_nonce'] = 'confidential';
		self::assertArrayNotHasKey('ai_nonce', ArenaRules::publicState($state));
		self::assertArrayNotHasKey('ai_started_at', ArenaRules::publicState($state));
	}
}
