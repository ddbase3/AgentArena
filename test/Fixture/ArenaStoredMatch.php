<?php declare(strict_types=1);

namespace AgentArena\Test\Fixture;

use Base3\Database\Api\IDatabase;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

/** Test-only SQL contract fixture. No production backend or alternate persistence service. */
final class ArenaStoredMatch {

	public array $state;
	public array $queries = [];
	public int $writes = 0;
	public int $affected = 0;
	public bool $conflict = false;
	public bool $exists = true;

	public function __construct(IDatabase&MockObject $database, array $state) {
		$this->state = $state;
		$database->method('escape')->willReturnCallback(
			static fn(string $value): string => addslashes($value)
		);
		$database->method('singleQuery')->willReturnCallback(function(string $query): ?array {
			$this->queries[] = $query;
			if (!$this->exists) { return null; }
			if (str_starts_with($query, 'SELECT id FROM')) { return ['id' => $this->state['id']]; }
			if (!str_starts_with($query, 'SELECT revision, payload FROM')) {
				throw new RuntimeException('Unexpected test query: ' . $query);
			}
			return ['revision' => $this->state['revision'], 'payload' => json_encode($this->state, JSON_THROW_ON_ERROR)];
		});
		$database->method('nonQuery')->willReturnCallback(function(string $query): void {
			$this->queries[] = $query;
			$this->writes++;
			$this->affected = 1;
			if (str_starts_with($query, 'UPDATE agentarena_matches')) {
				if (!preg_match("/revision = ([0-9]+), payload = '((?:\\\\.|[^'\\\\])*)', updated_at = CURRENT_TIMESTAMP /s", $query, $match) ||
					!preg_match('/ AND revision = ([0-9]+)$/D', $query, $expected)) {
					throw new RuntimeException('Unexpected test update query: ' . $query);
				}
				$this->affected = !$this->conflict && (int)$expected[1] === $this->state['revision'] ? 1 : 0;
				if ($this->affected === 1) {
					$next = json_decode(stripslashes($match[2]), true, 512, JSON_THROW_ON_ERROR);
					if ($next['revision'] !== (int)$match[1]) { throw new RuntimeException('Revision mismatch in test fixture.'); }
					$this->state = $next;
				}
			} elseif (str_starts_with($query, 'DELETE FROM agentarena_matches')) {
				$this->exists = false;
			} elseif (!str_starts_with($query, 'INSERT INTO agentarena_matches')) {
				throw new RuntimeException('Unexpected test write: ' . $query);
			}
		});
		$database->method('affectedRows')->willReturnCallback(fn(): int => $this->affected);
	}
}
