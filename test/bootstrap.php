<?php declare(strict_types=1);

/** Test-only autoloading for the installed ILIAS 11 components/Base3 module tree. */
$moduleRoot = dirname(__DIR__);
$modulesRoot = dirname($moduleRoot);
$iliasRoot = dirname($modulesRoot, 2);
$vendor = $iliasRoot . '/vendor/autoload.php';
if (is_file($vendor)) require_once $vendor;

$prefixes = [
	'Base3\\AgentArena\\Test\\' => __DIR__ . '/',
	'Base3\\AgentArena\\' => $moduleRoot . '/src/',
	'Base3\\CoreFoundation\\' => $modulesRoot . '/CoreFoundation/src/',
	'Base3\\AssistantFoundation\\' => $modulesRoot . '/AssistantFoundation/src/',
	'Base3\\MissionBay\\' => $modulesRoot . '/MissionBay/src/'
];
spl_autoload_register(static function(string $class) use ($prefixes): void {
	foreach ($prefixes as $prefix => $directory) {
		if (!str_starts_with($class, $prefix)) continue;
		$file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) require_once $file;
		return;
	}
});

if (!defined('DIR_PLUGIN')) define('DIR_PLUGIN', $modulesRoot . '/');
