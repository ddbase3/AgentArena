<?php declare(strict_types=1);

namespace AgentArena\Test\Display;

use RuntimeException;

/** Validates localized IDisplay help and fullscreen controls without a host. */
final class ArenaUiSmoke {

	private array $_ = [];

	public static function run(): void {
		$directory = dirname(__DIR__, 2);
		foreach (['de' => 'Anleitung', 'en' => 'How to play'] as $language => $expected) {
			$translations = parse_ini_file($directory . '/lang/Display/' . $language . '.ini', true, INI_SCANNER_RAW);
			if (!is_array($translations) || !is_array($translations['agentarenadisplay'] ?? null)) {
				throw new RuntimeException('Arena translations unavailable: ' . $language);
			}
			$labels = $translations['agentarenadisplay'];
			foreach (['help_button', 'help_title', 'help_intro', 'help_close', 'help_done',
				'help_how_1', 'help_how_2', 'help_how_3', 'help_how_4', 'help_scout',
				'help_guardian', 'help_ranger', 'help_combat', 'help_win', 'help_ai',
				'fullscreen_enter', 'fullscreen_exit', 'fullscreen_failed',
				'archive_button', 'archive_title', 'archive_empty', 'resume_match',
				'select_map', 'map_standard', 'map_bastion', 'map_crossfire',
				'objective_label', 'replay_button', 'replay_mode', 'replay_exit',
				'replay_unavailable', 'invalid_map', 'help_maps', 'help_replay'] as $key) {
				if (!isset($labels[$key]) || $labels[$key] === '') {
					throw new RuntimeException('Missing localized Arena key: ' . $language . '.' . $key);
				}
			}
			$view = new self();
			$view->_ = [
				'arena_config' => ['api' => '/arenaapioutput.json',
					'matchId' => '', 'text' => $labels, 'maps' => \AgentArena\Game\ArenaRules::maps()],
				'arena_css' => '/assets/arena.css',
				'arena_js' => '/assets/arena.js'
			];
			$html = $view->render($directory);
			foreach (['data-aa-help', 'data-aa-help-dialog', 'data-aa-help-close',
				'data-aa-help-done', 'data-aa-fullscreen', 'aria-haspopup="dialog"',
				'aria-pressed="false"', 'data-aa-map-dialog',
				'data-aa-map-choice="standard"', 'data-aa-map-choice="bastion"',
				'data-aa-map-choice="crossfire"', 'data-aa-history-dialog',
				'data-aa-replay-controls', 'data-aa-replay-range', 'data-aa-result', $expected] as $needle) {
				if (!str_contains($html, $needle)) {
					throw new RuntimeException('Arena UI missing ' . $needle);
				}
			}
			$view->_['arena_config']['text']['help_intro'] = '<script>alert(1)</script>';
			$escaped = $view->render($directory);
			if (str_contains($escaped, '<script>alert(1)</script>') ||
				!str_contains($escaped, '&lt;script&gt;alert(1)&lt;/script&gt;')) {
				throw new RuntimeException('Arena help did not escape translations.');
			}
		}
	}

	private function render(string $directory): string {
		ob_start();
		try {
			include $directory . '/tpl/Display/AgentArenaDisplay.php';
			return (string)ob_get_clean();
		} catch (\Throwable $error) {
			ob_end_clean();
			throw $error;
		}
	}
}
