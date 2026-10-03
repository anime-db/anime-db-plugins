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

use AnimeDb\PluginContracts\OAuth\OAuthStateMismatchException;
use AnimeDb\PluginContracts\OAuth\OAuthTokenExchangeException;
use AnimeDb\Plugins\AnimedbMyanimelist\OAuth\MalOAuthCallbackController;
use AnimeDb\Plugins\AnimedbMyanimelist\OAuth\MalOAuthClient;
use AnimeDb\Plugins\AnimedbMyanimelist\OAuth\MalTokenProbe;
use AnimeDb\Plugins\AnimedbMyanimelist\Tests\OAuth\Fixture\StubTwigFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MalOAuthCallbackControllerTest extends TestCase
{
    public function testUserDenialIsShownAsAFriendlyPageWithoutTouchingTheOAuthSession(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->expects(self::never())->method('handleCallback');
        $probe = $this->createMock(MalTokenProbe::class);
        $probe->expects(self::never())->method('check');

        $controller = new MalOAuthCallbackController($oauth, $probe, StubTwigFactory::create());
        $response = $controller(Request::create('/oauth/myanimelist', 'GET', ['error' => 'access_denied', 'state' => 'abc']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('close this tab and try again', (string) $response->getContent());
    }

    public function testMissingCodeIsShownAsAFriendlyPageWithoutTouchingTheOAuthSession(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->expects(self::never())->method('handleCallback');
        $probe = $this->createMock(MalTokenProbe::class);
        $probe->expects(self::never())->method('check');

        $controller = new MalOAuthCallbackController($oauth, $probe, StubTwigFactory::create());
        $response = $controller(Request::create('/oauth/myanimelist', 'GET', ['state' => 'abc']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('close this tab and try again', (string) $response->getContent());
    }

    public function testSuccessfulCallbackWithAWorkingProbeRendersDoneWithoutWarning(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->expects(self::once())->method('handleCallback')->with('the-state', 'the-code');
        $oauth->method('accessToken')->willReturn('the-access-token');

        $probe = $this->createMock(MalTokenProbe::class);
        $probe->expects(self::once())->method('check')->with('the-access-token')->willReturn(true);

        $controller = new MalOAuthCallbackController($oauth, $probe, StubTwigFactory::create());
        $response = $controller(Request::create('/oauth/myanimelist', 'GET', ['code' => 'the-code', 'state' => 'the-state']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Authorization complete. You can close this tab and return to the app.', (string) $response->getContent());
        self::assertStringNotContainsString('not completed', (string) $response->getContent());
        self::assertStringNotContainsString('did not succeed', (string) $response->getContent());
    }

    public function testSuccessfulCallbackWithAFailingProbeRendersDoneWithAWarning(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->method('accessToken')->willReturn('the-access-token');

        $probe = $this->createMock(MalTokenProbe::class);
        $probe->method('check')->willReturn(false);

        $controller = new MalOAuthCallbackController($oauth, $probe, StubTwigFactory::create());
        $response = $controller(Request::create('/oauth/myanimelist', 'GET', ['code' => 'the-code', 'state' => 'the-state']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Authorization complete. You can close this tab and return to the app.', (string) $response->getContent());
        self::assertStringNotContainsString('not completed', (string) $response->getContent());
        self::assertStringContainsString('did not succeed', (string) $response->getContent());
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function contractExceptionsProvider(): iterable
    {
        yield 'state mismatch' => [new OAuthStateMismatchException('mismatch')];
        yield 'token exchange failure' => [new OAuthTokenExchangeException('rejected')];
    }

    #[DataProvider('contractExceptionsProvider')]
    public function testHandleCallbackExceptionsAreShownAsAFriendlyPage(\Throwable $exception): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->method('handleCallback')->willThrowException($exception);

        $probe = $this->createMock(MalTokenProbe::class);
        $probe->expects(self::never())->method('check');

        $controller = new MalOAuthCallbackController($oauth, $probe, StubTwigFactory::create());
        $response = $controller(Request::create('/oauth/myanimelist', 'GET', ['code' => 'the-code', 'state' => 'the-state']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Could not complete MyAnimeList authorization', (string) $response->getContent());
        self::assertStringNotContainsString('was cancelled', (string) $response->getContent());
    }

    public function testTransportFailureDuringHandleCallbackIsShownAsAFriendlyPage(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->method('handleCallback')->willThrowException($this->createMock(ClientExceptionInterface::class));

        $probe = $this->createMock(MalTokenProbe::class);
        $probe->expects(self::never())->method('check');

        $controller = new MalOAuthCallbackController($oauth, $probe, StubTwigFactory::create());
        $response = $controller(Request::create('/oauth/myanimelist', 'GET', ['code' => 'the-code', 'state' => 'the-state']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Could not complete MyAnimeList authorization', (string) $response->getContent());
        self::assertStringNotContainsString('was cancelled', (string) $response->getContent());
    }
}
