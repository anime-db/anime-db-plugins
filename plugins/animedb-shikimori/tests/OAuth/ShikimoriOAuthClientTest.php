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

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use AnimeDb\Plugins\AnimedbShikimori\Http\UserAgent;
use AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * These abstract methods are `protected` (the contract's own design, see
 * {@see \AnimeDb\PluginContracts\OAuth\AbstractOAuthClient}), so they are exercised through
 * reflection rather than the public API — asserting on {@see ShikimoriOAuthClient::buildAuthorizeUrl()}'s
 * output alone would leave `tokenRequestHeaders()` and `clientSecret()` unverified without a
 * full HTTP round-trip.
 */
final class ShikimoriOAuthClientTest extends TestCase
{
    public function testVendorEndpointsAreHardcodedToShikimoriIo(): void
    {
        $client = $this->buildClient();

        self::assertSame('https://shikimori.io/oauth/authorize', $this->invoke($client, 'authorizeEndpoint'));
        self::assertSame('https://shikimori.io/oauth/token', $this->invoke($client, 'tokenEndpoint'));
    }

    public function testCallbackPathMatchesTheRegisteredRedirectUri(): void
    {
        $client = $this->buildClient();

        self::assertSame('/oauth/shikimori', $this->invoke($client, 'callbackPath'));
    }

    public function testScopesAndPkceMethodMatchApp2212Registration(): void
    {
        $client = $this->buildClient();

        self::assertSame(['user_rates'], $this->invoke($client, 'scopes'));
        self::assertSame('S256', $this->invoke($client, 'pkceMethod'));
    }

    public function testClientIsConfidentialAndReturnsANonNullSecret(): void
    {
        $client = $this->buildClient();

        self::assertIsString($this->invoke($client, 'clientId'));
        self::assertNotSame('', $this->invoke($client, 'clientId'));
        self::assertIsString($this->invoke($client, 'clientSecret'));
        self::assertNotSame('', $this->invoke($client, 'clientSecret'));
    }

    public function testTokenRequestHeadersCarryTheFillerFormattedUserAgent(): void
    {
        $manifest = $this->createMock(OwnManifestInterface::class);
        $manifest->method('id')->willReturn('animedb-shikimori');
        $manifest->method('version')->willReturn('0.3.0');
        $client = $this->buildClient($manifest);

        self::assertSame(
            ['User-Agent' => UserAgent::forManifest($manifest)],
            $this->invoke($client, 'tokenRequestHeaders'),
        );
    }

    private function buildClient(?OwnManifestInterface $manifest = null): ShikimoriOAuthClient
    {
        return new ShikimoriOAuthClient(
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class),
            $this->createMock(SettingsStoreInterface::class),
            $manifest ?? $this->createMock(OwnManifestInterface::class),
        );
    }

    private function invoke(ShikimoriOAuthClient $client, string $method): mixed
    {
        return (new \ReflectionMethod($client, $method))->invoke($client);
    }
}
