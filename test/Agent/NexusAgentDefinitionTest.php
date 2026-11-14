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


namespace Base3\AgentArena\Test\Agent;

use Base3\AgentArena\Agent\NexusAgentDefinition;
use Base3\AssistantFoundation\Api\IAgentDefinition;
use PHPUnit\Framework\TestCase;

final class NexusAgentDefinitionTest extends TestCase {

	public function testPermanentIdentityAndSafeEditableDefaults(): void {
		$agent = new NexusAgentDefinition();
		self::assertInstanceOf(IAgentDefinition::class, $agent);
		self::assertSame('nexusagentdefinition', $agent::getName());
		self::assertSame('agentarena.opponent', $agent->getId());
		self::assertSame('NEXUS', $agent->getLabel());
		$defaults = $agent->getDefaultSettings();
		self::assertFalse($defaults['enabled']);
		self::assertSame('missionbay', $defaults['agent_runtime']);
		self::assertSame('', $defaults['user_prompt']);
		self::assertSame(['arena-agent-tool-profile'], $defaults['tool_profiles']);
		self::assertSame('manualonlyjobpolicy', $defaults['policy']['policy']);
		self::assertStringContainsString('arena_get_state', $defaults['system_prompt']);
		self::assertStringContainsString('arena_submit_turn', $defaults['system_prompt']);
		self::assertStringContainsString('NEXUS', $defaults['system_prompt']);
	}

	public function testDefinitionReturnsStableDefaultsWithoutExternalState(): void {
		$agent = new NexusAgentDefinition();
		self::assertSame($agent->getDefaultSettings(), $agent->getDefaultSettings());
	}
}
