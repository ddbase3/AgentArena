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


namespace Base3\AgentArena;

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
