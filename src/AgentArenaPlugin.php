<?php declare(strict_types=1);

namespace AgentArena;

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

/** Registers only the services owned by the arena. Displays, tools and migrations use class-map discovery. */
final class AgentArenaPlugin implements IPlugin {

	public function __construct(private readonly IContainer $container) {}

	public static function getName(): string { return 'agentarenaplugin'; }

	public function init(): void {
		$this->container->set(ArenaMatchRepository::class,
			fn(IContainer $c) => new ArenaMatchRepository($c->get(IDatabase::class)),
			IContainer::SHARED | IContainer::NOOVERWRITE);
		$this->container->set(ArenaAccessService::class,
			fn(IContainer $c) => new ArenaAccessService(
				$c->get(IUsermanager::class), $c->get(IAuthorizationService::class)),
			IContainer::SHARED | IContainer::NOOVERWRITE);
		$this->container->set(ArenaGameService::class,
			fn(IContainer $c) => new ArenaGameService(
				$c->get(ArenaMatchRepository::class), $c->get(ArenaAccessService::class)),
			IContainer::SHARED | IContainer::NOOVERWRITE);
		$this->container->set(ArenaAgentTurnService::class,
			fn(IContainer $c) => new ArenaAgentTurnService(
				$c->get(ArenaGameService::class),
				$c->get(IAgentConfigurationService::class),
				$c->get(IAgentExecutionService::class)),
			IContainer::SHARED | IContainer::NOOVERWRITE);
	}
}
