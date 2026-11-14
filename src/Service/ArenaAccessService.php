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


namespace Base3\AgentArena\Service;

use Base3\AgentArena\Authorization\ArenaPlayFeature;
use Base3\CoreFoundation\Authorization\Api\IAuthorizationService;
use Base3\CoreFoundation\Authorization\Dto\AuthorizationRequest;
use Base3\CoreFoundation\Usermanager\Api\IUsermanager;
use Base3\CoreFoundation\Usermanager\Permission;
use RuntimeException;

/** Resolves trusted host actor and delegates the single permission decision to its authority. */
final class ArenaAccessService {

	public function __construct(
		private readonly IUsermanager $users,
		private readonly IAuthorizationService $authorization
	) {}

	/** @return array{authority:string,id:string} */
	public function requireActor(): array {
		$user = $this->users->getUser();
		$id = ($user === null) ? '' : trim((string)$user->id);
		if ($id === '') {
			throw new RuntimeException('arena.unauthenticated');
		}
		$authority = $this->authorization->getDefaultAuthority();
		$decision = $this->authorization->decide(new AuthorizationRequest(
			$authority, Permission::for('feature', ArenaPlayFeature::getName())
		));
		if (!$decision->isAllowed()) {
			throw new RuntimeException('arena.access_denied');
		}
		return ['authority' => $authority, 'id' => $id];
	}
}
