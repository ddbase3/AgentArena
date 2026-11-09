<?php declare(strict_types=1);

namespace AgentArena\Test\Agent;

use AgentArena\Agent\NexusAgentDefinition;
use AssistantFoundation\Api\IAgentDefinition;
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
