<?php declare(strict_types=1);

namespace AgentArena\Authorization;

use Base3\Api\IMvcView;
use Base3\Authorization\Api\IFeature;
use Base3\Usermanager\Permission;
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
