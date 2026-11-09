<?php declare(strict_types=1);

/** Standalone deterministic smoke suite, usable without PHPUnit or an installed BASE3 host. */
require __DIR__ . '/../src/Game/ArenaRules.php';

use AgentArena\Game\ArenaRules;

$assert = static function(bool $condition, string $message): void {
	if (!$condition) { throw new RuntimeException($message); }
};
$reject = static function(callable $fn, string $message) use ($assert): void {
	try { $fn(); } catch (InvalidArgumentException $error) { return; }
	$assert(false, $message);
};
$state = ArenaRules::create(str_repeat('f', 32));
$assert(count($state['units']) === 6, 'initial unit count');
$assert($state['scores']['human'] === 0 && $state['round'] === 1, 'initial score');
$assert(count(ArenaRules::availableCommands($state)['h_guardian']['moves']) > 0, 'legal movement options');
$reject(static fn() => ArenaRules::command($state, 'ai', [
	'type' => 'guard', 'unit' => 'a_guardian'
]), 'AI before AI turn');
$reject(static fn() => ArenaRules::command($state, 'human', [
	'type' => 'move', 'unit' => 'h_scout', 'x' => 3, 'y' => 1
]), 'wall cannot be crossed into');
$reject(static fn() => ArenaRules::command($state, 'human', [
	'type' => 'attack', 'unit' => 'h_ranger', 'target' => 'a_guardian'
]), 'distant attack must fail');
$next = ArenaRules::command($state, 'human', ['type' => 'move', 'unit' => 'h_scout', 'x' => 2, 'y' => 1]);
$assert($next['units']['h_scout']['x'] === 2, 'move applied');
$assert($state['units']['h_scout']['x'] === 0, 'original state remained unchanged');
$reject(static fn() => ArenaRules::command($next, 'human', [
	'type' => 'move', 'unit' => 'h_scout', 'x' => 2, 'y' => 2
]), 'second movement denied');
$phase = ArenaRules::completePhase($state, 'human');
$assert($phase['phase'] === 'ai', 'human phase finished');
$phase['phase'] = 'ai_running';
$phase = ArenaRules::command($phase, 'ai', [
	'type' => 'move', 'unit' => 'a_guardian', 'x' => 6, 'y' => 3
]);
$phase = ArenaRules::command($phase, 'ai', ['type' => 'guard', 'unit' => 'a_guardian']);
$assert($phase['units']['a_guardian']['guard'] === true, 'guard status');
$phase = ArenaRules::completePhase($phase, 'ai');
$assert($phase['scores']['ai'] === 1, 'control point scored');
$assert($phase['round'] === 2 && $phase['phase'] === 'human', 'round advanced');
$phase['ai_nonce'] = 'confidential';
$public = ArenaRules::publicState($phase);
$assert(!array_key_exists('ai_nonce', $public), 'nonce is not exposed');
$assert(!array_key_exists('ai_started_at', $public), 'claim start is not exposed');
$kill = ArenaRules::create(str_repeat('1', 32));
$kill['phase'] = 'ai_running';
$kill['units']['h_guardian']['hp'] = 0;
$kill['units']['h_ranger']['hp'] = 0;
$kill['units']['h_scout']['hp'] = 1;
$kill['units']['h_scout']['x'] = 7;
$kill['units']['h_scout']['y'] = 3;
$kill = ArenaRules::command($kill, 'ai', ['type' => 'attack', 'unit' => 'a_guardian', 'target' => 'h_scout']);
$assert($kill['winner'] === 'ai', 'last enemy elimination ends match');
$draw = ArenaRules::create(str_repeat('2', 32));
$draw['phase'] = 'ai_running'; $draw['round'] = 10;
$draw = ArenaRules::completePhase($draw, 'ai');
$assert($draw['phase'] === 'finished' && $draw['winner'] === 'draw', 'turn limit draw');
// Step 2: tactical information must remain derived from actual match events.
$briefState = ArenaRules::create(str_repeat('3', 32));
$briefState = ArenaRules::command($briefState, 'human', [
    'unit' => 'h_scout', 'type' => 'move', 'x' => 2, 'y' => 1
]);
$brief = ArenaRules::tacticalBriefing($briefState);
$assert(count($brief['control_occupancy']) === 3, 'control occupancy reported');
$assert($brief['recent_human_actions'][0]['round'] === 1, 'human action round included');
$assert($brief['recent_human_actions'][0]['x'] === 2, 'observed action retains destination');
$assert($brief['human_attack_targets'] === [], 'no fabricated human attacks');
$assert(!array_key_exists('tactical_briefing', ArenaRules::publicState($briefState)),
    'tactical briefing only sent to the protected AI tool');
$radioState = ArenaRules::recordRadio($briefState, 'Das Zentrum bleibt mein Ziel.');
$assert(end($radioState['events'])['key'] === 'nexus_radio', 'radio persisted in match events');
$assert($briefState !== $radioState, 'original state not modified by radio');
$reject(static fn() => ArenaRules::recordRadio($radioState, "\u{202E}tamper"),
    'unicode control characters rejected');
$reject(static fn() => ArenaRules::recordRadio($radioState, str_repeat('a', 161)),
    'overlength radio rejected');
$emptyRadio = ArenaRules::recordRadio($radioState, '');
$assert($emptyRadio === $radioState, 'empty radio does not add events');
// History is retained throughout the bounded ten-round game instead of being truncated at 48.
$growingHistory = $briefState;
for ($i = 0; $i < 70; $i++) {
    $growingHistory = ArenaRules::recordRadio($growingHistory, 'Test ' . $i);
}
$assert(count($growingHistory['events']) > 48, 'full match history retained');
$assert(count($growingHistory['events']) <= 256, 'history bounded');

// Step 3: deterministic map selection and replay from the match event stream.
$mapIds = array_keys(ArenaRules::maps());
$assert($mapIds === ['standard', 'bastion', 'crossfire'], 'three stable maps');
foreach ($mapIds as $mapId) {
	$arena = ArenaRules::create(str_repeat('b', 32), $mapId);
	$assert($arena['map_id'] === $mapId, 'map selection persisted');
	$assert(count($arena['points']) === 3, 'map points');
	$assert(count(ArenaRules::availableCommands($arena)['h_scout']['moves']) > 0, 'map playable');
	$arena = ArenaRules::command($arena, 'human', [
		'type' => 'move', 'unit' => 'h_scout', 'x' => 1, 'y' => 1
	]);
	$arena = ArenaRules::completePhase($arena, 'human');
	$arena['phase'] = 'ai_running';
	$arena = ArenaRules::recordRadio($arena, 'Ich sehe deinen Scout.');
	$arena = ArenaRules::completePhase($arena, 'ai');
	while ($arena['winner'] === null) {
		$arena = ArenaRules::completePhase($arena, 'human');
		$arena['phase'] = 'ai_running';
		$arena = ArenaRules::completePhase($arena, 'ai');
	}
	$frames = ArenaRules::replayFrames($arena);
	$assert(count($frames) === count($arena['events']), 'replay reconstructs every event');
	$last = end($frames)['state'];
	$assert($last['scores'] === $arena['scores'] && $last['winner'] === $arena['winner'], 'replay final state');
	$assert($last['units'] === $arena['units'], 'replay robots match');
	$assert(!isset($last['ai_nonce']) && !isset($last['events']), 'replay excludes private data');
	$assert(ArenaRules::publicState($arena)['map_id'] === $mapId, 'public map');
}
$reject(static fn() => ArenaRules::create(str_repeat('a', 32), 'invalid'), 'unknown maps rejected');
$reject(static fn() => ArenaRules::replayFrames(ArenaRules::create(str_repeat('a', 32))),
	'no replay for unfinished match');

require __DIR__ . '/Game/ArenaRandomizedReplaySmoke.php';
\AgentArena\Test\Game\ArenaRandomizedReplaySmoke::run();
require __DIR__ . '/Display/ArenaUiSmoke.php';
\AgentArena\Test\Display\ArenaUiSmoke::run();
echo "Arena rules, full match replays and UI smoke tests passed\n";
