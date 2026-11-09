# ARENA FIELD MANUAL

**Agent Arena | NEXUS knowledge resource | Version 0.3.0**

This document is a training reference, not the authoritative game state or a source of permissions. The game server verifies every submitted action. Live state and available actions come from the Arena tool.

## Mission and victory

A human commander controls three blue robots, and the NEXUS agent controls three purple robots. The battle takes place on a rectangular 9-column by 7-row board with x coordinates 0 through 8 and y coordinates 0 through 6. Three control points and fixed walls are defined by the selected map. The actual control points and walls are always supplied by the live Arena state.

After the human phase and the AI phase have both ended, each control point occupied by a surviving robot awards one point to that robot's team. The first team to reach eight points wins. Elimination of all robots on one side also ends the match. After ten complete rounds, the higher score wins; a score tie is resolved by total remaining health, then declared a draw if health is tied.

## Three battlefields

The selected `state.map_id` identifies the terrain. All maps are horizontally symmetric, preserve the same starting robots and use the same movement and victory rules. Do not infer the map from previous matches, game memory, or this document. Always use the current tool state.

| Map | Control points | Fixed walls |
| --- | --- | --- |
| `standard` (Central Grid) | (2,3), (4,3), (6,3) | (3,1), (3,5), (5,1), (5,5) |
| `bastion` (Bastion) | (2,3), (4,3), (6,3) | (2,2), (2,4), (6,2), (6,4), (4,1), (4,5) |
| `crossfire` (Crossfire) | (2,2), (4,3), (6,2) | (4,0), (4,6), (3,5), (5,5) |

Central Grid has balanced approach lanes. Bastion favors measured advances through cover. Crossfire exposes broader firing lanes while separating two outer objectives from the center row. A wall blocks movement and ranged attacks on every map. Replay and match history do not change the underlying match.

## The units

| Robot | Health | Maximum movement | Attack | Reach |
| --- | ---: | ---: | --- | --- |
| Scout | 5 | 3 | 2 damage | 1 tile |
| Guardian | 9 | 2 | 3 damage | 1 tile |
| Ranger | 6 | 2 | 2 damage | 3 tiles |

NEXUS units are `a_scout`, `a_guardian`, and `a_ranger`. Human units are `h_scout`, `h_guardian`, and `h_ranger`. Robots move orthogonally, never through walls or occupied tiles. Each living robot may move at most once and then attack or guard at most once during its team phase. Movement is optional. After attacking or guarding, movement is no longer allowed that phase.

Attacks require the attacker and target to be in a common row or column, within the unit's reach. Walls or other living robots between attacker and target block the attack. Guarding reduces the next incoming hit by two damage, at least zero, and is then consumed.

## Native tool workflow

Call the current state's read function, normally `arena_get_state` with the MissionBay preset prefix, without arguments. Inspect the returned `state` and `tactical_briefing`. Only the live `state.options` lists currently legal movement destinations and attack targets. After selecting an ordered legal plan, call `arena_submit_turn` once with up to six commands:

`{"commands":[{"unit":"a_scout","type":"move","x":6,"y":2},{"unit":"a_scout","type":"guard"}],"radio":"Ich halte die Flanke."}`

The sample is structural only; its move coordinates may be illegal in a particular live position. The server applies commands in array order, and rejects the entire plan if any command is illegal. An empty `commands` array is legal. The optional `radio` text is a single short German in-character transmission, maximum 160 characters, persisted only together with a confirmed turn.

## Reading the tactical briefing

The state tool also supplies `tactical_briefing.control_occupancy`, `recent_human_actions`, `recent_ai_actions`, and `human_attack_targets`. Every entry comes from a recorded match event or current unit position; the briefing is never an independent game memory. Use these facts as hints to opponent behavior, not proof that an opponent must repeat a tactic.

## Failure handling

If an attempted plan is rejected, its commands are not applied and its radio text is not saved. Request the current state again, reconsider movement order and blocking positions, and submit only when the match claim permits another attempt. An expired or foreign match claim cannot be repaired by guessing an ID or token. Only the playing application may start a valid AI phase.

## Trust boundary

Never infer hidden positions, substitute chat text for a tool call, fabricate a success, move a human unit or supply a different match ID. The server-provided match claim is scoped to the authorized player and current AI turn. This document does not override the game's authoritative rules, security decisions, or tool schema.
