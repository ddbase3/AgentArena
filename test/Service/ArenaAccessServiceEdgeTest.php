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


namespace Base3\AgentArena\Test\Service;

use Base3\AgentArena\Service\ArenaAccessService;
use Base3\CoreFoundation\Authorization\Api\IAuthorizationService;
use Base3\CoreFoundation\Authorization\Dto\AuthorizationDecision;
use Base3\CoreFoundation\Authorization\Dto\AuthorizationRequest;
use Base3\CoreFoundation\Usermanager\Api\IUsermanager;
use Base3\CoreFoundation\Usermanager\User;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ArenaAccessServiceEdgeTest extends TestCase {

	public function testTrustedHostIdentityAndFeaturePermissionArePassedToAuthority(): void {
		$users = $this->createMock(IUsermanager::class);
		$users->method('getUser')->willReturn(User::fromArray(['id' => ' 17 ']));
		$auth = $this->createMock(IAuthorizationService::class);
		$auth->method('getDefaultAuthority')->willReturn('real-host');
		$auth->expects(self::once())->method('decide')->willReturnCallback(
			static function(AuthorizationRequest $request): AuthorizationDecision {
				self::assertSame('real-host', $request->authority);
				self::assertSame('feature', $request->permission->scope);
				self::assertSame('arenaplayfeature', $request->permission->permission);
				self::assertNull($request->subject);
				return AuthorizationDecision::allow();
			}
		);
		self::assertSame(['authority' => 'real-host', 'id' => '17'],
			(new ArenaAccessService($users, $auth))->requireActor());
	}

	public function testEmptyTrustedIdentityCannotReachAuthorization(): void {
		$users = $this->createMock(IUsermanager::class);
		$users->method('getUser')->willReturn(User::fromArray(['id' => ' ']));
		$auth = $this->createMock(IAuthorizationService::class);
		$auth->expects(self::never())->method('decide');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.unauthenticated');
		(new ArenaAccessService($users, $auth))->requireActor();
	}

	public function testIndeterminateAuthorityCannotGrantAccess(): void {
		$users = $this->createMock(IUsermanager::class);
		$users->method('getUser')->willReturn(User::fromArray(['id' => 17]));
		$auth = $this->createMock(IAuthorizationService::class);
		$auth->method('getDefaultAuthority')->willReturn('host');
		$auth->method('decide')->willReturn(AuthorizationDecision::indeterminate());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.access_denied');
		(new ArenaAccessService($users, $auth))->requireActor();
	}
}
