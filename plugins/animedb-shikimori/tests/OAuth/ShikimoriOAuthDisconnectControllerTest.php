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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\OAuth;

use AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthClient;
use AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthDisconnectController;
use AnimeDb\Plugins\AnimedbShikimori\Tests\Settings\Fixture\FakeSettingsStore;
use AnimeDb\Plugins\AnimedbShikimori\Tests\Settings\Fixture\StubTwigFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ShikimoriOAuthDisconnectControllerTest extends TestCase
{
    private const VALID_TOKEN = 'valid-token';

    public function testValidRequestDisconnectsAndRerendersAsNotAuthorized(): void
    {
        $store = new FakeSettingsStore(['api_endpoint' => 'https://shikimori.one']);
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        $oauth->expects(self::once())->method('disconnect');
        $oauth->method('accessToken')->willReturn(null);

        $controller = $this->makeController($store, $oauth);
        $response = $controller(self::postRequest());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Not authorized', (string) $response->getContent());
    }

    public function testInvalidCsrfTokenIsRejectedWithoutDisconnecting(): void
    {
        $store = new FakeSettingsStore([]);
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        $oauth->expects(self::never())->method('disconnect');
        $oauth->method('accessToken')->willReturn('still-there');

        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(false);
        $controller = new ShikimoriOAuthDisconnectController($store, $oauth, $csrfTokenManager, StubTwigFactory::create());

        $response = $controller(self::postRequest('wrong-token'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Invalid CSRF token', (string) $response->getContent());
    }

    private function makeController(FakeSettingsStore $store, ShikimoriOAuthClient $oauth): ShikimoriOAuthDisconnectController
    {
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')
            ->willReturnCallback(static fn (CsrfToken $token): bool => $token->getValue() === self::VALID_TOKEN);

        return new ShikimoriOAuthDisconnectController($store, $oauth, $csrfTokenManager, StubTwigFactory::create());
    }

    private static function postRequest(string $token = self::VALID_TOKEN): Request
    {
        return Request::create('/plugins/animedb-shikimori/oauth/disconnect', 'POST', ['_token' => $token]);
    }
}
