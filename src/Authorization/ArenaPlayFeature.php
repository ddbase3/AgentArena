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


namespace Base3\AgentArena\Authorization;

use Base3\CoreFoundation\Api\IMvcView;
use Base3\CoreFoundation\Authorization\Api\IFeature;
use Base3\CoreFoundation\Usermanager\Permission;
use RuntimeException;

/** Advertises the game feature to the existing BASE3 authorization service. */
final class ArenaPlayFeature implements IFeature {

	public function __construct(private readonly IMvcView $view) {}

	public static function getName(): string { return 'arenaplayfeature'; }

	public function getLabel(): string { return 'Agent Arena'; }

	public function getDescription(): string {
		$this->view->setPath(DIR_PLUGIN . 'AgentArena');
		$this->view->loadBricks('Display');
		$text = $this->view->getBricks('agentarenadisplay');
		if (!is_array($text) || !isset($text['feature_description'])) {
			throw new RuntimeException('arena.translations_unavailable');
		}
		return $text['feature_description'];
	}

	public function getPermissions(): array {
		return [Permission::for('feature', self::getName())];
	}

	public function getDefaultAudience(): array { return ['group' => 'authenticated']; }
}
