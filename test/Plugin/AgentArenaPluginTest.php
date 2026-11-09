<?php declare(strict_types=1);

namespace AgentArena\Test\Plugin;

use AgentArena\AgentArenaPlugin;
use AgentArena\Repository\ArenaMatchRepository;
use AgentArena\Service\ArenaAccessService;
use AgentArena\Service\ArenaAgentTurnService;
use AgentArena\Service\ArenaGameService;
use AssistantFoundation\Api\IAgentConfigurationService;
use AssistantFoundation\Api\IAgentExecutionService;
use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Database\Api\IDatabase;
use Base3\Usermanager\Api\IUsermanager;
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
