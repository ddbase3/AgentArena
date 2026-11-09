<?php declare(strict_types=1);

/** PHPUnit bootstrap; uses the installed BASE3 and Foundation sources, never starts a host request. */
$projectRoot = dirname(__DIR__, 3);
$vendor = $projectRoot . '/vendor/autoload.php';
if (is_file($vendor)) { require_once $vendor; }

$prefixes = [
	'AgentArena\\Test\\' => __DIR__ . '/',
	'AgentArena\\' => dirname(__DIR__) . '/src/',
	'Base3\\' => $projectRoot . '/Base3Framework/src/',
	'AssistantFoundation\\' => $projectRoot . '/plugin/AssistantFoundation/src/',
	'MissionBay\\' => $projectRoot . '/plugin/MissionBay/src/'
];
spl_autoload_register(static function(string $class) use ($prefixes): void {
	foreach ($prefixes as $prefix => $path) {
		if (!str_starts_with($class, $prefix)) { continue; }
		$file = $path . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) { require_once $file; }
		return;
	}
});

if (!defined('DIR_PLUGIN')) { define('DIR_PLUGIN', $projectRoot . '/plugin/'); }
