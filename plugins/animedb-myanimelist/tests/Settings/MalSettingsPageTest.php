<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace AnimeDb\Plugins\AnimedbMyanimelist\Tests\Settings;

use AnimeDb\Plugins\AnimedbMyanimelist\OAuth\MalOAuthClient;
use AnimeDb\Plugins\AnimedbMyanimelist\Settings\MalSettingsPage;
use AnimeDb\Plugins\AnimedbMyanimelist\Tests\Settings\Fixture\StubTwigFactory;
use PHPUnit\Framework\TestCase;

final class MalSettingsPageTest extends TestCase
{
    public function testRenderShowsNotAuthorizedAndAuthorizeLinkWhenNoAccessTokenIsStored(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->method('accessToken')->willReturn(null);

        $page = new MalSettingsPage($oauth, StubTwigFactory::create());
        $html = $page->render();

        self::assertStringContainsString('Not authorized', $html);
        self::assertStringContainsString('/animedb_myanimelist_oauth_start', $html);
        self::assertStringNotContainsString('Re-authorize', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testRenderShowsAuthorizedReauthorizeLinkAndDisconnectFormWhenAnAccessTokenIsStored(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->method('accessToken')->willReturn('the-token');

        $page = new MalSettingsPage($oauth, StubTwigFactory::create());
        $html = $page->render();

        self::assertStringContainsString('Authorized', $html);
        self::assertStringNotContainsString('Not authorized', $html);
        self::assertStringContainsString('Re-authorize', $html);
        self::assertStringContainsString('/animedb_myanimelist_oauth_start', $html);
        self::assertStringContainsString('<form', $html);
        self::assertStringContainsString('/animedb_myanimelist_oauth_disconnect', $html);
        self::assertStringContainsString('stub-csrf-token-for-animedb_myanimelist_oauth_disconnect', $html);
    }

    public function testAuthorizeStartLinksAreNeverHtmxDrivenRegardlessOfAuthorizationState(): void
    {
        $oauthAuthorized = $this->createMock(MalOAuthClient::class);
        $oauthAuthorized->method('accessToken')->willReturn('the-token');
        $authorizedHtml = (new MalSettingsPage($oauthAuthorized, StubTwigFactory::create()))->render();

        $oauthUnauthorized = $this->createMock(MalOAuthClient::class);
        $oauthUnauthorized->method('accessToken')->willReturn(null);
        $unauthorizedHtml = (new MalSettingsPage($oauthUnauthorized, StubTwigFactory::create()))->render();

        foreach ([$authorizedHtml, $unauthorizedHtml] as $html) {
            self::assertMatchesRegularExpression('/<a\s+href="\/animedb_myanimelist_oauth_start"[^>]*>/', $html);
            self::assertDoesNotMatchRegularExpression('/<a[^>]*hx-[a-z-]+[^>]*href="\/animedb_myanimelist_oauth_start"/', $html);
            self::assertDoesNotMatchRegularExpression('/<a\s+href="\/animedb_myanimelist_oauth_start"[^>]*hx-[a-z-]+/', $html);
        }
    }
}
