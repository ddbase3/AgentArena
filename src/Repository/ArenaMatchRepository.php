<?php declare(strict_types=1);

namespace AgentArena\Repository;

use Base3\Database\Api\IDatabase;
use RuntimeException;

/** Owns one authoritative JSON match document in the application's selected database. */
final class ArenaMatchRepository {

	public function __construct(private readonly IDatabase $database) {}

	/** @param array{authority:string,id:string} $actor */
	public function create(array $actor, array $state): void {
		$this->database->connect();
		$id = $this->quote((string)$state['id']);
		$authority = $this->quote($actor['authority']);
		$owner = $this->quote($actor['id']);
		$payload = $this->quote(json_encode($state, JSON_THROW_ON_ERROR));
		$this->database->nonQuery("INSERT INTO agentarena_matches " .
			"(id, authority, owner_id, revision, payload, created_at, updated_at) VALUES " .
			"($id, $authority, $owner, 0, $payload, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
	}

	/** Returns the most recently changed match for the acting native user. No client-side mirror is kept.
	 *  @param array{authority:string,id:string} $actor */
	public function latestOwned(array $actor): ?array {
		$this->database->connect();
		$row = $this->database->singleQuery("SELECT id FROM agentarena_matches WHERE authority = " .
			$this->quote($actor['authority']) . " AND owner_id = " . $this->quote($actor['id']) .
			" ORDER BY updated_at DESC, id DESC LIMIT 1");
		if ($row === null) { return null; }
		return $this->loadOwned((string)$row['id'], $actor);
	}

	/**
	 * Lists a bounded set of the owner's real matches, with metadata derived
	 * from their authoritative payloads. No additional history table exists.
	 * @param array{authority:string,id:string} $actor
	 * @return array<int,array<string,mixed>>
	 */
	public function listOwned(array $actor): array {
		$this->database->connect();
		$rows = $this->database->multiQuery('SELECT id, payload, created_at, updated_at ' .
			'FROM agentarena_matches WHERE authority = ' . $this->quote($actor['authority']) .
			' AND owner_id = ' . $this->quote($actor['id']) .
			' ORDER BY updated_at DESC, id DESC LIMIT 20');
		$matches = [];
		foreach ($rows as $row) {
			$state = json_decode((string)$row['payload'], true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($state) || ($state['id'] ?? null) !== $row['id']) {
				throw new RuntimeException('arena.invalid_state');
			}
			$matches[] = [
				'id' => $row['id'],
				'map_id' => $state['map_id'] ?? 'standard',
				'round' => $state['round'],
				'phase' => $state['phase'],
				'winner' => $state['winner'],
				'scores' => $state['scores'],
				'created_at' => $row['created_at'],
				'updated_at' => $row['updated_at']
			];
		}
		return $matches;
	}

	/** @param array{authority:string,id:string} $actor
	 *  @return array<string,mixed> */
	public function loadOwned(string $id, array $actor): array {
		$this->assertId($id);
		$this->database->connect();
		$row = $this->database->singleQuery("SELECT revision, payload FROM agentarena_matches " .
			"WHERE id = " . $this->quote($id) .
			" AND authority = " . $this->quote($actor['authority']) .
			" AND owner_id = " . $this->quote($actor['id']));
		if ($row === null) { throw new RuntimeException('arena.match_not_found'); }
		$state = json_decode((string)$row['payload'], true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($state) || ($state['id'] ?? '') !== $id) {
			throw new RuntimeException('arena.invalid_state');
		}
		$state['revision'] = (int)$row['revision'];
		return $state;
	}

	/** Atomically commits an exact next revision; rejects concurrent or stale modifications.
	 *  @param array{authority:string,id:string} $actor */
	public function replace(array $actor, array $state, int $expectedRevision): void {
		$this->assertId((string)$state['id']);
		$next = $expectedRevision + 1;
		$state['revision'] = $next;
		$payload = $this->quote(json_encode($state, JSON_THROW_ON_ERROR));
		$this->database->connect();
		$this->database->nonQuery("UPDATE agentarena_matches SET revision = $next, " .
			"payload = $payload, updated_at = CURRENT_TIMESTAMP " .
			"WHERE id = " . $this->quote($state['id']) .
			" AND authority = " . $this->quote($actor['authority']) .
			" AND owner_id = " . $this->quote($actor['id']) .
			" AND revision = $expectedRevision");
		if ($this->database->affectedRows() !== 1) {
			throw new RuntimeException('arena.stale_state');
		}
	}

	/** Deletes all matches for one trusted native owner, including their in-progress AI claims.
	 *  @param array{authority:string,id:string} $actor */
	public function deleteAllOwned(array $actor): void {
		$this->database->connect();
		$this->database->nonQuery("DELETE FROM agentarena_matches WHERE authority = " .
			$this->quote($actor['authority']) . " AND owner_id = " . $this->quote($actor['id']));
	}

	private function quote(string $value): string { return "'" . $this->database->escape($value) . "'"; }

	private function assertId(string $id): void {
		if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new RuntimeException('arena.match_not_found'); }
	}
}
