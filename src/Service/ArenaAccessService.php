<?php declare(strict_types=1);

namespace AgentArena\Service;

use AgentArena\Authorization\ArenaPlayFeature;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
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
