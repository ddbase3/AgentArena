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


namespace Base3\AgentArena\Migration;

use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\Migration\Api\IDatabaseMigrationProvider;

/** Provides one immutable arena schema area only where the host has selected a database. */
final class ArenaMatchMigrationProvider implements IDatabaseMigrationProvider {

	public function __construct(private readonly IContainer $container) {}

	public static function getName(): string { return 'arenamatchmigrationprovider'; }

	public function isActive(): bool { return $this->container->has(IDatabase::class); }

	public function getMigrations(): array { return [ArenaMigration001CreateMatches::class]; }
}
