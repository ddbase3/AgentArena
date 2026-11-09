# Agent Arena 0.3.0: Final game iteration

## Scope

Three selectable fixed maps, owner-scoped match archive (20 latest),
replay of completed games reconstructed with `ArenaRules`, upgraded sci-fi
interface with accessible dialogs, robot SVGs, HP and point indicators,
movement and hit transitions, match outcome panel, and extended help.

## Configuration

The installed NEXUS configuration is unchanged. Use the existing
`arena-agent-tool-profile`, which supplies `ArenaAgentTool` with both
`arena_get_state` and `arena_submit_turn`. User prompt is dynamic; memory and
context profile are optional and not required by this game.

`ArenaRules::maps()` is the source of `standard`, `bastion`, `crossfire`.
The front-end preview data comes from that method, while `start` independently
validates the selected ID at the rule boundary. No schema migration is
needed: `map_id` lives in the existing JSON payload. Existing matches
without `map_id` remain compatible.

## Replay integrity and access

`history` is an owner-filtered list of real match metadata, not stored
history data. `replay` is an owner-filtered read of a finished match. Each
recorded move is passed through `ArenaRules::command`, each completed phase
through `ArenaRules::completePhase`, and each radio event through
`ArenaRules::recordRadio`. The final positions, health, round, score, phase
and winner must exactly match the authoritative saved state. Incomplete
history returns `replay_unavailable`. The replay does not mutate persistence.

## Test matrix

| Check | Local verification | Host verification |
| --- | --- | --- |
| Three maps and symmetry | Standalone, 15 full matches and PHPUnit test source | pending |
| Replay reconstruction, tamper rejection | Standalone, 15 full matches and PHPUnit test source | pending |
| PHP and JavaScript syntax | Local lint | pending |
| German/English strings and dialogs | PHP template smoke | pending |
| Same-Origin JSON and feature authorization | Existing boundary tests unchanged | pending |
| Real database query for recent matches | Contract-checked against IDatabase | pending |
| Live NEXUS tool use on all maps | Not available in container | pending |
| Responsive visual QA and WCAG 2.2 AA | Browser unable to launch in container | pending |
| Concurrent clients and rollback | Source-level unchanged CAS/ownership | pending |

## Installation and recovery

Extract the complete ZIP over the existing accepted plugin root using
`unzip -o`. There are no new schema changes or removed production classes.
The existing legacy-tool cleanup script may be run once if upgrading from
pre-0.1.2 installations. If an upgrade is rejected, restore the previously
accepted 0.2.1 files from version control or the saved known-good ZIP.
The original match table and data must not be deleted for rollback.

## Data life cycle

The archive and replay add no persistent records. Data ownership, deletion,
retention and possible external LLM disclosures remain in `PRIVACY.md`.

## Wartungsfix 0.3.1: Eingabefreigabe nach AJAX

Beim Laden eines Spielstands rendert die UI noch waehrend `busy=true`.
Die bisherige Kopplung der DOM-Button-Aktivierung an `isPlaying()` hinterliess
die menschlichen Figuren dauerhaft deaktiviert, weil `setBusy(false)` nur
Aktionsbuttons neu zeichnete. Die DOM-Auswahl richtet sich jetzt nach
`isHumanTurn()` (Spielphase und Replay), waehrend `isPlaying()` weiterhin
ungewuenschte Aktionen bei laufenden Requests blockiert. Dies erhaelt
die Animationen und aendert keine serverseitige Spielregel oder Berechtigung.

Der neue Node-basierte DOM-Smoketest prueft das Aktivieren der Felder nach
`resume`, nach einem menschlichen Spielzug und nach der NEXUS-Phase.
