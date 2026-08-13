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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Settings;

use AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthClient;
use AnimeDb\Plugins\AnimedbShikimori\Settings\ShikimoriSettingsController;
use AnimeDb\Plugins\AnimedbShikimori\Tests\Settings\Fixture\FakeSettingsStore;
use AnimeDb\Plugins\AnimedbShikimori\Tests\Settings\Fixture\StubTwigFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ShikimoriSettingsControllerTest extends TestCase
{
    private const VALID_TOKEN = 'valid-token';

    public function testValidEndpointIsNormalizedAndSavedWhilePreservingOtherKeys(): void
    {
        $store = new FakeSettingsStore(['oauth_access_token' => 'keep-me']);
        $controller = $this->makeController($store);

        $response = $controller(self::postRequest(['api_endpoint' => '  https://shikimori.one/  ']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([
            'oauth_access_token' => 'keep-me',
            'api_endpoint' => 'https://shikimori.one/',
        ], $store->data);
        self::assertStringContainsString('Saved', (string) $response->getContent());
    }

    public function testResponseShowsAuthorizedStatusWhenAnAccessTokenIsPresent(): void
    {
        $store = new FakeSettingsStore([]);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')
            ->willReturnCallback(static fn (CsrfToken $token): bool => $token->getValue() === self::VALID_TOKEN);
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        $oauth->method('accessToken')->willReturn('the-token');
        $controller = new ShikimoriSettingsController($store, $oauth, $csrfTokenManager, StubTwigFactory::create());

        $response = $controller(self::postRequest(['api_endpoint' => '']));

        self::assertStringContainsString('Authorized', (string) $response->getContent());
        self::assertStringNotContainsString('Not authorized', (string) $response->getContent());
    }

    public function testEmptyEndpointRemovesTheKeyButKeepsOtherKeys(): void
    {
        $store = new FakeSettingsStore(['api_endpoint' => 'https://shikimori.one', 'oauth_access_token' => 'keep-me']);
        $controller = $this->makeController($store);

        $response = $controller(self::postRequest(['api_endpoint' => '   ']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(['oauth_access_token' => 'keep-me'], $store->data);
    }

    public function testImplausibleEndpointIsRejectedWithoutWriting(): void
    {
        $store = new FakeSettingsStore(['api_endpoint' => 'https://shikimori.one']);
        $controller = $this->makeController($store);

        $response = $controller(self::postRequest(['api_endpoint' => 'not-a-url']));

        self::assertSame(['api_endpoint' => 'https://shikimori.one'], $store->data);
        self::assertStringContainsString('valid https', (string) $response->getContent());
    }

    public function testHttpEndpointIsRejectedWithoutWriting(): void
    {
        $store = new FakeSettingsStore([]);
        $controller = $this->makeController($store);

        $controller(self::postRequest(['api_endpoint' => 'http://shikimori.io']));

        self::assertSame([], $store->data);
    }

    public function testInvalidCsrfTokenIsRejectedWithoutWriting(): void
    {
        $store = new FakeSettingsStore(['api_endpoint' => 'https://shikimori.one']);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(false);
        $controller = new ShikimoriSettingsController($store, $this->createMock(ShikimoriOAuthClient::class), $csrfTokenManager, StubTwigFactory::create());

        $response = $controller(self::postRequest(['api_endpoint' => 'https://shikimori.io'], token: 'wrong-token'));

        // HTMX only swaps 2xx responses (hx-swap="outerHTML"), so the inline error and the
        // refreshed CSRF token must travel back in a 200, or the user never sees them.
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Invalid CSRF token', (string) $response->getContent());
        self::assertSame(['api_endpoint' => 'https://shikimori.one'], $store->data);
    }

    public function testNonScalarInputIsRejectedInlineWithoutCrashing(): void
    {
        $store = new FakeSettingsStore(['api_endpoint' => 'https://shikimori.one']);
        $controller = $this->makeController($store);

        $request = Request::create('/plugins/animedb-shikimori/settings', 'POST', [
            '_token' => [self::VALID_TOKEN],
            'api_endpoint' => 'https://shikimori.io',
        ]);

        $response = $controller($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Invalid form submission', (string) $response->getContent());
        self::assertSame(['api_endpoint' => 'https://shikimori.one'], $store->data);
    }

    public function testWriteFailureIsReportedInlineWithoutCrashing(): void
    {
        $store = new FakeSettingsStore([]);
        $store->failOnNextUpdate();
        $controller = $this->makeController($store);

        $response = $controller(self::postRequest(['api_endpoint' => 'https://shikimori.io']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Could not save', (string) $response->getContent());
    }

    public function testConcurrentWriteIsReportedInlineWithoutCrashing(): void
    {
        $store = new FakeSettingsStore([]);
        $store->throwConcurrentWriteOnNextUpdate();
        $controller = $this->makeController($store);

        $response = $controller(self::postRequest(['api_endpoint' => 'https://shikimori.io']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('busy', (string) $response->getContent());
    }

    private function makeController(FakeSettingsStore $store): ShikimoriSettingsController
    {
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')
            ->willReturnCallback(static fn (CsrfToken $token): bool => $token->getValue() === self::VALID_TOKEN);

        return new ShikimoriSettingsController($store, $this->createMock(ShikimoriOAuthClient::class), $csrfTokenManager, StubTwigFactory::create());
    }

    /**
     * @param array<string, string> $parameters
     */
    private static function postRequest(array $parameters, string $token = self::VALID_TOKEN): Request
    {
        return Request::create('/plugins/animedb-shikimori/settings', 'POST', ['_token' => $token, ...$parameters]);
    }
}
