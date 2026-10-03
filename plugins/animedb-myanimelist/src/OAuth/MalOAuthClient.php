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

namespace AnimeDb\Plugins\AnimedbMyanimelist\OAuth;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\OAuth\AbstractOAuthClient;
use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\UserAgent;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * MyAnimeList's Authorization Code + PKCE client. The MyAnimeList application this plugin
 * uses was issued only a `client_id`, no client secret — a public client — so
 * {@see self::clientSecret()} returns null and {@see self::pkceMethod()} returns `plain`
 * rather than `S256`.
 *
 * The application's registered `redirect_uri` is `http://127.0.0.1:41813/oauth/myanimelist`
 * (MyAnimeList compares it byte-for-byte, including the port). {@see self::callbackPath()}
 * returns only the path; the host supplies the matching `http://127.0.0.1:41813` origin at
 * runtime via `$_SERVER['OAUTH_CALLBACK_ORIGIN']` — see
 * {@see AbstractOAuthClient}'s class doc for how the two are combined.
 *
 * The authorize/token endpoints are hardcoded to `myanimelist.net`, never derived from a
 * user-editable setting: this plugin has no such setting at all, and
 * {@see AbstractOAuthClient}'s class doc explains why an OAuth endpoint specifically must
 * never be one (a refresh token would leak to whatever host a setting pointed at).
 *
 * {@see self::clientId()} returns {@see MalApiClient::CLIENT_ID} — the same constant used
 * for the anonymous `X-MAL-CLIENT-ID` header — rather than a second literal copy of the same
 * public value.
 *
 * {@see self::tokenRequestHeaders()} adds `User-Agent`, same as every other request this
 * plugin makes to MyAnimeList ({@see \AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient}).
 */
class MalOAuthClient extends AbstractOAuthClient
{
    private const AUTHORIZE_ENDPOINT = 'https://myanimelist.net/v1/oauth2/authorize';
    private const TOKEN_ENDPOINT = 'https://myanimelist.net/v1/oauth2/token';
    private const CALLBACK_PATH = '/oauth/myanimelist';

    /**
     * MyAnimeList's OAuth 2 implementation does not use scopes.
     *
     * @var string[]
     */
    private const SCOPES = [];

    private const PKCE_METHOD = 'plain';

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
        return MalApiClient::CLIENT_ID;
    }

    protected function clientSecret(): ?string
    {
        return null;
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
