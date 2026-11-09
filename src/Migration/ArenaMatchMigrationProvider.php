<?php declare(strict_types=1);

namespace AgentArena\Migration;

use Base3\Api\IContainer;
use Base3\Database\Api\IDatabase;
use Base3\Migration\Api\IDatabaseMigrationProvider;

/** Provides one immutable arena schema area only where the host has selected a database. */
final class ArenaMatchMigrationProvider implements IDatabaseMigrationProvider {

	public function __construct(private readonly IContainer $container) {}

	public static function getName(): string { return 'arenamatchmigrationprovider'; }

	public function isActive(): bool { return $this->container->has(IDatabase::class); }

	public function getMigrations(): array { return [ArenaMigration001CreateMatches::class]; }
}
