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


namespace Base3\AgentArena\Test\Plugin;

use Base3\AgentArena\AgentArenaPlugin;
use Base3\AgentArena\Repository\ArenaMatchRepository;
use Base3\AgentArena\Service\ArenaAccessService;
use Base3\AgentArena\Service\ArenaAgentTurnService;
use Base3\AgentArena\Service\ArenaGameService;
use Base3\AssistantFoundation\Api\IAgentConfigurationService;
use Base3\AssistantFoundation\Api\IAgentExecutionService;
use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Api\IPlugin;
use Base3\CoreFoundation\Authorization\Api\IAuthorizationService;
use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\Usermanager\Api\IUsermanager;
use PHPUnit\Framework\TestCase;

final class AgentArenaPluginTest extends TestCase {

	public function testInitRegistersOnlyExistingOwnedServiceGraph(): void {
		$container = $this->createMock(IContainer::class);
		$factories = [];
		$container->expects(self::exactly(4))->method('set')->willReturnCallback(
			static function(string $name, mixed $definition, int $flags) use (&$factories, $container): IContainer {
				self::assertSame(IContainer::SHARED | IContainer::NOOVERWRITE, $flags);
				self::assertIsCallable($definition);
				self::assertArrayNotHasKey($name, $factories);
				$factories[$name] = $definition;
				return $container;
			}
		);
		$services = [
			IDatabase::class => $this->createMock(IDatabase::class),
			IUsermanager::class => $this->createMock(IUsermanager::class),
			IAuthorizationService::class => $this->createMock(IAuthorizationService::class),
			IAgentConfigurationService::class => $this->createMock(IAgentConfigurationService::class),
			IAgentExecutionService::class => $this->createMock(IAgentExecutionService::class)
		];
		$container->method('get')->willReturnCallback(
			function(string $name) use (&$factories, &$services, $container): mixed {
				if (!array_key_exists($name, $services)) {
					$services[$name] = $factories[$name]($container);
				}
				return $services[$name];
			}
		);
		$plugin = new AgentArenaPlugin($container);
		self::assertInstanceOf(IPlugin::class, $plugin);
		self::assertSame('agentarenaplugin', $plugin::getName());
		$plugin->init();
		self::assertSame([
			ArenaMatchRepository::class, ArenaAccessService::class,
			ArenaGameService::class, ArenaAgentTurnService::class
		], array_keys($factories));
		foreach (array_keys($factories) as $name) {
			self::assertInstanceOf($name, $container->get($name));
		}
	}
}
