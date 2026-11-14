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


namespace Base3\AgentArena\Test\Authorization;

use Base3\AgentArena\Authorization\ArenaPlayFeature;
use Base3\CoreFoundation\Api\IMvcView;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__) . '/bootstrap.php';

final class ArenaPlayFeatureTest extends TestCase {

	public function testFeatureExposesExactlyOneOwnedPermissionAndAuthenticatedAudience(): void {
		$view = $this->createMock(IMvcView::class);
		$view->expects(self::once())->method('setPath')->with(DIR_PLUGIN . 'AgentArena');
		$view->expects(self::once())->method('loadBricks')->with('Display');
		$view->method('getBricks')->willReturn(['feature_description' => 'Arena play']);
		$feature = new ArenaPlayFeature($view);
		self::assertSame('arenaplayfeature', ArenaPlayFeature::getName());
		self::assertSame('Agent Arena', $feature->getLabel());
		self::assertSame('Arena play', $feature->getDescription());
		self::assertSame(['group' => 'authenticated'], $feature->getDefaultAudience());
		$permissions = $feature->getPermissions();
		self::assertCount(1, $permissions);
		self::assertSame('feature', $permissions[0]->scope);
		self::assertSame('arenaplayfeature', $permissions[0]->permission);
	}

	public function testMissingFeatureTranslationFailsRatherThanReturningUntranslatedText(): void {
		$view = $this->createMock(IMvcView::class);
		$view->method('getBricks')->willReturn([]);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.translations_unavailable');
		(new ArenaPlayFeature($view))->getDescription();
	}
}
