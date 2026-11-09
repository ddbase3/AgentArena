<?php declare(strict_types=1);

namespace AgentArena\Test\Service;

use AgentArena\Service\ArenaAccessService;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\User;
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
