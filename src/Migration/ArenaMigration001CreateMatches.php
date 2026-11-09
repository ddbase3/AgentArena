<?php declare(strict_types=1);

namespace AgentArena\Migration;

use Base3\Database\Api\IDatabase;
use Base3\Migration\Api\IDatabaseMigration;

/** Initial, immutable MySQL/MariaDB schema for authoritative match state. */
final class ArenaMigration001CreateMatches implements IDatabaseMigration {

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
}
