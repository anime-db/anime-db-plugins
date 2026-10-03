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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Tests\OAuth;

use AnimeDb\Plugins\AnimedbMyanimelist\OAuth\MalOAuthClient;
use AnimeDb\Plugins\AnimedbMyanimelist\OAuth\MalOAuthDisconnectController;
use AnimeDb\Plugins\AnimedbMyanimelist\Tests\Settings\Fixture\StubTwigFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class MalOAuthDisconnectControllerTest extends TestCase
{
    private const VALID_TOKEN = 'valid-token';

    public function testValidRequestDisconnectsAndRerendersAsNotAuthorized(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->expects(self::once())->method('disconnect');
        $oauth->method('accessToken')->willReturn(null);

        $controller = $this->makeController($oauth);
        $response = $controller(self::postRequest());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Not authorized', (string) $response->getContent());
    }

    public function testInvalidCsrfTokenIsRejectedWithoutDisconnecting(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->expects(self::never())->method('disconnect');
        $oauth->method('accessToken')->willReturn('still-there');

        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(false);
        $controller = new MalOAuthDisconnectController($oauth, $csrfTokenManager, StubTwigFactory::create());

        $response = $controller(self::postRequest('wrong-token'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Invalid CSRF token', (string) $response->getContent());
    }

    private function makeController(MalOAuthClient $oauth): MalOAuthDisconnectController
    {
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')
            ->willReturnCallback(static fn (CsrfToken $token): bool => $token->getValue() === self::VALID_TOKEN);

        return new MalOAuthDisconnectController($oauth, $csrfTokenManager, StubTwigFactory::create());
    }

    private static function postRequest(string $token = self::VALID_TOKEN): Request
    {
        return Request::create('/plugins/animedb-myanimelist/oauth/disconnect', 'POST', ['_token' => $token]);
    }
}
