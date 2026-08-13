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

namespace AnimeDb\Plugins\AnimedbShikimori\OAuth;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\OAuth\AbstractOAuthClient;
use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use AnimeDb\Plugins\AnimedbShikimori\Http\UserAgent;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Shikimori's Authorization Code + PKCE (S256) client, registered as Shikimori OAuth
 * application #2212 with `redirect_uri` = `http://127.0.0.1/oauth/shikimori` (portless, exact
 * path — RFC 8252 §7.3 only relaxes the port for a loopback redirect, not the path).
 *
 * The authorize/token endpoints are hardcoded to `shikimori.io`, never derived from the
 * user-editable `api_endpoint` setting {@see \AnimeDb\Plugins\AnimedbShikimori\Settings\ShikimoriSettingsPage}
 * exposes: {@see AbstractOAuthClient}'s class doc explains why (a user tricked into pointing
 * `api_endpoint` at an attacker's host would otherwise leak a refresh token to it on the next
 * background refresh).
 *
 * Shikimori's app #2212 is a confidential client, so {@see self::clientSecret()} ships the
 * secret with the plugin rather than returning null — an accepted design decision (see issue
 * #40), same as many "installed app" OAuth clients whose secret cannot really be kept
 * confidential in a distributed binary/plugin anyway.
 *
 * {@see self::tokenRequestHeaders()} adds `User-Agent`: Shikimori bans token/refresh requests
 * without one, the same as anonymous GraphQL reads ({@see \AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlClient}).
 * That is also why this class, unlike a "plain" {@see AbstractOAuthClient} subclass, injects
 * {@see OwnManifestInterface} in addition to the base class's own dependencies.
 */
class ShikimoriOAuthClient extends AbstractOAuthClient
{
    private const AUTHORIZE_ENDPOINT = 'https://shikimori.io/oauth/authorize';
    private const TOKEN_ENDPOINT = 'https://shikimori.io/oauth/token';
    private const CALLBACK_PATH = '/oauth/shikimori';
    private const SCOPES = ['user_rates'];
    private const PKCE_METHOD = 'S256';

    /**
     * Shikimori app #2212's credentials. Confidential-client secrets are not something an
     * autonomous change can source on its own — the maintainer fills the real values in
     * before the OAuth 0.4.0 release (the plugin version is deliberately not bumped by this
     * change, see issue #40's "Границы").
     */
    private const CLIENT_ID = 'dGHCKk7JH1o546hnWMueT3xodrONWNpVWU_eQbMqc08';
    private const CLIENT_SECRET = 'Z6EAyxXuWKxPAUikq5RsaJFGPfVhggJ2psKTaRJLcw0';

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        SettingsStoreInterface $settings,
        private readonly OwnManifestInterface $ownManifest,
    ) {
        parent::__construct($httpClient, $requestFactory, $streamFactory, $settings);
    }

    protected function authorizeEndpoint(): string
    {
        return self::AUTHORIZE_ENDPOINT;
    }

    protected function tokenEndpoint(): string
    {
        return self::TOKEN_ENDPOINT;
    }

    protected function clientId(): string
    {
        return self::CLIENT_ID;
    }

    protected function clientSecret(): ?string
    {
        return self::CLIENT_SECRET;
    }

    /**
     * @return string[]
     */
    protected function scopes(): array
    {
        return self::SCOPES;
    }

    protected function pkceMethod(): string
    {
        return self::PKCE_METHOD;
    }

    protected function callbackPath(): string
    {
        return self::CALLBACK_PATH;
    }

    /**
     * @return array<string, string>
     */
    protected function tokenRequestHeaders(): array
    {
        return ['User-Agent' => UserAgent::forManifest($this->ownManifest)];
    }
}
