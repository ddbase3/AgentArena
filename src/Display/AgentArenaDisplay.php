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


namespace Base3\AgentArena\Display;

use Base3\AgentArena\Service\ArenaAccessService;
use Base3\AgentArena\Game\ArenaRules;
use Base3\CoreFoundation\Api\IAssetResolver;
use Base3\CoreFoundation\Api\IDisplay;
use Base3\CoreFoundation\Api\IMvcView;
use Base3\CoreFoundation\LinkTarget\Api\ILinkTargetService;
use Throwable;

/** Portable game widget. Hosts embed this IDisplay; the existing link service addresses its JSON output. */
final class AgentArenaDisplay implements IDisplay {

	private array $data = [];

	public function __construct(
		private readonly IMvcView $view,
		private readonly IAssetResolver $assets,
		private readonly ILinkTargetService $links,
		private readonly ArenaAccessService $access
	) {}

	public static function getName(): string { return 'agentarenadisplay'; }

	public function setData($data) {
		$this->data = is_array($data) ? $data : [];
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		if ($out !== 'html') { return ''; }
		$this->view->setPath(DIR_PLUGIN . 'AgentArena');
		$this->view->loadBricks('Display');
		$messages = $this->view->getBricks('agentarenadisplay');
		if (!is_array($messages)) { throw new \RuntimeException('Arena translations unavailable.'); }
		try {
			$this->access->requireActor();
		} catch (Throwable $error) {
			return '<p role="alert">' . htmlspecialchars($messages['access_denied'], ENT_QUOTES, 'UTF-8') . '</p>';
		}
		$matchId = is_string($this->data['match_id'] ?? null) ? $this->data['match_id'] : '';
		if (!preg_match('/^[a-f0-9]{32}$/D', $matchId)) { $matchId = ''; }
		$this->view->setTemplate('Display/AgentArenaDisplay.php');
		$this->view->assign('arena_config', [
			'api' => $this->links->getLink(['name' => 'arenaapioutput', 'out' => 'json']),
			'matchId' => $matchId,
			'maps' => ArenaRules::maps(),
			'text' => $messages
		]);
		$this->view->assign('arena_css', $this->assets->resolve('plugin/AgentArena/assets/arena/arena.css'));
		$this->view->assign('arena_js', $this->assets->resolve('plugin/AgentArena/assets/arena/arena.js'));
		return $this->view->loadTemplate();
	}
}
