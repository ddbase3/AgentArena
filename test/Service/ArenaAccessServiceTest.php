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
use Base3\CoreFoundation\Usermanager\Api\IUsermanager;
use Base3\CoreFoundation\Usermanager\User;
use PHPUnit\Framework\TestCase;

/** Deny and accepted identity are always delegated to the host's authority. */
final class ArenaAccessServiceTest extends TestCase {

	public function testAllowedHostIdentity(): void {
		$users = $this->createMock(IUsermanager::class);
		$users->method('getUser')->willReturn(User::fromArray(['id' => 12]));
		$auth = $this->createMock(IAuthorizationService::class);
		$auth->method('getDefaultAuthority')->willReturn('test-host');
		$auth->expects(self::once())->method('decide')->willReturn(AuthorizationDecision::allow());
		self::assertSame(['authority' => 'test-host', 'id' => '12'],
			(new ArenaAccessService($users, $auth))->requireActor());
	}

	public function testDeniedHostPermission(): void {
		$users = $this->createMock(IUsermanager::class);
		$users->method('getUser')->willReturn(User::fromArray(['id' => 12]));
		$auth = $this->createMock(IAuthorizationService::class);
		$auth->method('getDefaultAuthority')->willReturn('test-host');
		$auth->method('decide')->willReturn(AuthorizationDecision::deny());
		$this->expectException(\RuntimeException::class);
		(new ArenaAccessService($users, $auth))->requireActor();
	}

	public function testAnonymousDeniedBeforeAuthority(): void {
		$users = $this->createMock(IUsermanager::class);
		$users->method('getUser')->willReturn(null);
		$auth = $this->createMock(IAuthorizationService::class);
		$auth->expects(self::never())->method('decide');
		$this->expectException(\RuntimeException::class);
		(new ArenaAccessService($users, $auth))->requireActor();
	}
}
