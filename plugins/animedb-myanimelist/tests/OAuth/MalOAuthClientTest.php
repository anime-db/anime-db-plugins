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

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient;
use AnimeDb\Plugins\AnimedbMyanimelist\OAuth\MalOAuthClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * These abstract methods are `protected` (the contract's own design, see
 * {@see \AnimeDb\PluginContracts\OAuth\AbstractOAuthClient}), so they are exercised through
 * reflection rather than the public API — asserting on {@see MalOAuthClient::buildAuthorizeUrl()}'s
 * output alone would leave `tokenRequestHeaders()` and `clientSecret()` unverified without a
 * full HTTP round-trip.
 */
final class MalOAuthClientTest extends TestCase
{
    public function testVendorEndpointsAreHardcodedToMyAnimeListNet(): void
    {
        $client = $this->buildClient();

        self::assertSame('https://myanimelist.net/v1/oauth2/authorize', $this->invoke($client, 'authorizeEndpoint'));
        self::assertSame('https://myanimelist.net/v1/oauth2/token', $this->invoke($client, 'tokenEndpoint'));
    }

    public function testCallbackPathMatchesTheRegisteredRedirectUri(): void
    {
        $client = $this->buildClient();

        self::assertSame('/oauth/myanimelist', $this->invoke($client, 'callbackPath'));
    }

    public function testScopesAreEmptyAndPkceMethodIsPlain(): void
    {
        $client = $this->buildClient();

        self::assertSame([], $this->invoke($client, 'scopes'));
        self::assertSame('plain', $this->invoke($client, 'pkceMethod'));
    }

    public function testClientIsPublicAndReturnsANullSecret(): void
    {
        $client = $this->buildClient();

        self::assertNull($this->invoke($client, 'clientSecret'));
    }

    public function testClientIdReusesTheConstantSharedWithTheAnonymousApiClient(): void
    {
        $client = $this->buildClient();

        self::assertSame(MalApiClient::CLIENT_ID, $this->invoke($client, 'clientId'));
    }

    public function testTokenRequestHeadersCarryTheFillerFormattedUserAgent(): void
    {
        $manifest = $this->createMock(OwnManifestInterface::class);
        $manifest->method('id')->willReturn('test-vendor-plugin');
        $manifest->method('version')->willReturn('9.9.9-test');
        $client = $this->buildClient($manifest);

        self::assertSame(
            ['User-Agent' => 'AnimeDB test-vendor-plugin/9.9.9-test (+https://anime-db.org/)'],
            $this->invoke($client, 'tokenRequestHeaders'),
        );
    }

    private function buildClient(?OwnManifestInterface $manifest = null): MalOAuthClient
    {
        return new MalOAuthClient(
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class),
            $this->createMock(SettingsStoreInterface::class),
            $manifest ?? $this->createMock(OwnManifestInterface::class),
        );
    }

    private function invoke(MalOAuthClient $client, string $method): mixed
    {
        return (new \ReflectionMethod($client, $method))->invoke($client);
    }
}
