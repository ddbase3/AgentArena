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


namespace Base3\AgentArena\Test\Display;

use Base3\AgentArena\Display\AgentArenaDisplay;
use Base3\AgentArena\Game\ArenaRules;
use Base3\AgentArena\Service\ArenaAccessService;
use Base3\CoreFoundation\Api\IAssetResolver;
use Base3\CoreFoundation\Api\IMvcView;
use Base3\CoreFoundation\Authorization\Api\IAuthorizationService;
use Base3\CoreFoundation\Authorization\Dto\AuthorizationDecision;
use Base3\CoreFoundation\LinkTarget\Api\ILinkTargetService;
use Base3\CoreFoundation\Usermanager\Api\IUsermanager;
use Base3\CoreFoundation\Usermanager\User;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__) . '/bootstrap.php';

final class AgentArenaDisplayTest extends TestCase {

	public function testRendersOwnedGameWithOnlyValidatedMatchIdAndResolvedAssets(): void {
		$view = $this->createMock(IMvcView::class);
		$view->method('getBricks')->willReturn(['access_denied' => 'No access']);
		$assigned = [];
		$view->method('assign')->willReturnCallback(
			static function(string $key, mixed $value) use (&$assigned): void { $assigned[$key] = $value; }
		);
		$view->expects(self::once())->method('setTemplate')->with('Display/AgentArenaDisplay.php');
		$view->expects(self::once())->method('loadTemplate')->willReturn('<div>arena</div>');
		$assets = $this->createMock(IAssetResolver::class);
		$assets->expects(self::exactly(2))->method('resolve')->willReturnCallback(
			static fn(string $path): string => '/static/' . basename($path)
		);
		$links = $this->createMock(ILinkTargetService::class);
		$links->expects(self::once())->method('getLink')->with(['name' => 'arenaapioutput', 'out' => 'json'])->willReturn('/arena.json');
		$display = new AgentArenaDisplay($view, $assets, $links, $this->access());
		self::assertSame('agentarenadisplay', AgentArenaDisplay::getName());
		$display->setData(['match_id' => str_repeat('a', 32)]);
		self::assertSame('<div>arena</div>', $display->getOutput());
		self::assertSame(str_repeat('a', 32), $assigned['arena_config']['matchId']);
		self::assertSame('/arena.json', $assigned['arena_config']['api']);
		self::assertSame(ArenaRules::maps(), $assigned['arena_config']['maps']);
		self::assertSame('/static/arena.css', $assigned['arena_css']);
		self::assertSame('/static/arena.js', $assigned['arena_js']);
	}

	public function testUntrustedMatchIdIsNotPassedToBrowser(): void {
		$view = $this->createMock(IMvcView::class);
		$view->method('getBricks')->willReturn(['access_denied' => 'No access']);
		$view->method('loadTemplate')->willReturn('ok');
		$received = [];
		$view->method('assign')->willReturnCallback(
			static function(string $key, mixed $value) use (&$received): void { $received[$key] = $value; }
		);
		$assets = $this->createMock(IAssetResolver::class);
		$assets->method('resolve')->willReturn('/asset');
		$links = $this->createMock(ILinkTargetService::class);
		$links->method('getLink')->willReturn('/api');
		$display = new AgentArenaDisplay($view, $assets, $links, $this->access());
		$display->setData(['match_id' => "oops<script>"]);
		self::assertSame('ok', $display->getOutput());
		self::assertSame('', $received['arena_config']['matchId']);
		$display->setData('not-an-array');
		self::assertSame('ok', $display->getOutput());
		self::assertSame('', $received['arena_config']['matchId']);
	}

	public function testDeniedUserOnlySeesEscapedAccessMessage(): void {
		$view = $this->createMock(IMvcView::class);
		$view->method('getBricks')->willReturn(['access_denied' => '<script>unsafe</script>']);
		$view->expects(self::never())->method('setTemplate');
		$assets = $this->createMock(IAssetResolver::class);
		$assets->expects(self::never())->method('resolve');
		$links = $this->createMock(ILinkTargetService::class);
		$links->expects(self::never())->method('getLink');
		$display = new AgentArenaDisplay($view, $assets, $links, $this->access(false));
		self::assertSame('<p role="alert">&lt;script&gt;unsafe&lt;/script&gt;</p>', $display->getOutput());
	}

	public function testNonHtmlRequestDoesNothing(): void {
		$view = $this->createMock(IMvcView::class);
		$view->expects(self::never())->method('loadBricks');
		$display = new AgentArenaDisplay($view, $this->createMock(IAssetResolver::class),
			$this->createMock(ILinkTargetService::class), $this->access());
		self::assertSame('', $display->getOutput('json'));
	}

	public function testMissingTranslationsFailBeforeRendering(): void {
		$view = $this->createMock(IMvcView::class);
		$view->method('getBricks')->willReturn(null);
		$display = new AgentArenaDisplay($view, $this->createMock(IAssetResolver::class),
			$this->createMock(ILinkTargetService::class), $this->access());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Arena translations unavailable.');
		$display->getOutput();
	}

	private function access(bool $allowed = true): ArenaAccessService {
		$users = $this->createMock(IUsermanager::class);
		$users->method('getUser')->willReturn(User::fromArray(['id' => 17]));
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('host');
		$authorization->method('decide')->willReturn($allowed ? AuthorizationDecision::allow() : AuthorizationDecision::deny());
		return new ArenaAccessService($users, $authorization);
	}
}
