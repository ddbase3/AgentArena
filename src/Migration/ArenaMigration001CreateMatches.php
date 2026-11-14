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

use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\Migration\Api\IDatabaseMigration;
use Base3\CoreFoundation\Migration\Api\ILegacyDatabaseMigration;
use Base3\CoreFoundation\Migration\Exception\MigrationException;

/** Initial, immutable MySQL/MariaDB schema for authoritative match state. */
final class ArenaMigration001CreateMatches implements IDatabaseMigration, ILegacyDatabaseMigration {

	public function __construct(private readonly IDatabase $database) {}

	public static function getName(): string { return 'arenamigration001creatematches'; }

	public function getVersion(): string { return '001'; }

	public function getDescription(): string { return 'Create Agent Arena matches'; }

	public function up(): void {
		$this->database->connect();
		$this->database->nonQuery('CREATE TABLE IF NOT EXISTS agentarena_matches (' .
			'id CHAR(32) NOT NULL PRIMARY KEY, ' .
			'authority VARCHAR(128) NOT NULL, ' .
			'owner_id VARCHAR(190) NOT NULL, ' .
			'revision INT UNSIGNED NOT NULL DEFAULT 0, ' .
			'payload LONGTEXT NOT NULL, ' .
			'created_at DATETIME NOT NULL, ' .
			'updated_at DATETIME NOT NULL, ' .
			'INDEX agentarena_owner_idx (authority, owner_id, updated_at)' .
			') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
	}

	public function down(): void {
		throw new MigrationException('Not implemented');
	}
}
