<?php declare(strict_types=1);

namespace AgentArena\Test\Service;

use AgentArena\Service\ArenaAccessService;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\User;
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
