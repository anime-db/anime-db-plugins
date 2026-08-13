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
use AnimeDb\Plugins\AnimedbShikimori\Http\UserAgent;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * A single `GET /api/users/whoami` call made once, right after a successful
 * {@see ShikimoriOAuthClient::handleCallback()}, to confirm the freshly exchanged token and
 * its `user_rates` scope actually work against Shikimori — not merely that a token got
 * saved. See issue #40: without this, phase 3 would ship a token nothing ever exercises
 * until phase 4 (sync) lands.
 *
 * Deliberately not fatal to the callback: {@see self::check()} returns a bool rather than
 * throwing, so a probe failure (Shikimori hiccup, unexpected scope rejection, ...) only
 * downgrades the callback's result page to a warning — the token exchange itself already
 * succeeded and is kept.
 */
class ShikimoriTokenProbe
{
    private const WHOAMI_ENDPOINT = 'https://shikimori.io/api/users/whoami';
    private const HTTP_SUCCESS_STATUS_MIN = 200;
    private const HTTP_SUCCESS_STATUS_MAX_EXCLUSIVE = 300;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly OwnManifestInterface $ownManifest,
    ) {
    }

    public function check(string $accessToken): bool
    {
        $request = $this->requestFactory->createRequest('GET', self::WHOAMI_ENDPOINT)
            ->withHeader('Authorization', 'Bearer '.$accessToken)
            ->withHeader('User-Agent', UserAgent::forManifest($this->ownManifest));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            error_log(\sprintf('animedb-shikimori: OAuth token probe failed to reach Shikimori: %s', $exception->getMessage()));

            return false;
        }

        $status = $response->getStatusCode();
        $ok = $status >= self::HTTP_SUCCESS_STATUS_MIN && $status < self::HTTP_SUCCESS_STATUS_MAX_EXCLUSIVE;

        if (!$ok) {
            error_log(\sprintf('animedb-shikimori: OAuth token probe responded with HTTP %d.', $status));
        }

        return $ok;
    }
}
