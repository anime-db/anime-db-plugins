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

namespace AnimeDb\Plugins\AnimedbWorldart\Http;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Client of the site's HTML pages, built on PSR-18/PSR-17 only.
 *
 * The site has no API and answers on `http://` only, so the base is a constant and nothing is read
 * from the settings. Every call goes through the cross-process limiter and then to the network:
 * nothing is cached. The body is handed to {@see WorldartPage} as it came, in `windows-1251`.
 */
final class WorldartClient
{
    public const BASE_URL = 'http://www.world-art.ru';

    private const NOT_CONNECT_PATH = '/not_connect.html';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly OwnManifestInterface $ownManifest,
        private readonly RequestLimiter $limiter,
    ) {
    }

    /**
     * A card page. A page without the title of the entry is returned as is; a redirect to the
     * "site is unavailable" page is a failure.
     *
     * @param callable(): void|null $onHeartbeat called while waiting for a limiter slot
     *
     * @throws WorldartRequestException
     */
    public function fetchCard(int $id, ?callable $onHeartbeat = null): WorldartPage
    {
        if ($id < 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid World-Art id "%d".', $id));
        }

        return $this->download(\sprintf('/animation/animation.php?id=%d', $id), $onHeartbeat);
    }

    /**
     * A search page for the query already encoded in `windows-1251`. A redirect to the "site is
     * unavailable" page is a failure, not an empty answer (see {@see download()}).
     *
     * @param callable(): void|null $onHeartbeat called while waiting for a limiter slot
     *
     * @throws WorldartRequestException
     */
    public function search(string $encodedQuery, ?callable $onHeartbeat = null): WorldartPage
    {
        return $this->download(
            '/search.php?public_search='.urlencode($encodedQuery).'&global_sector=animation',
            $onHeartbeat,
        );
    }

    /**
     * @param callable(): void|null $onHeartbeat
     */
    private function download(string $pathAndQuery, ?callable $onHeartbeat): WorldartPage
    {
        $this->limiter->acquire($onHeartbeat);

        $request = $this->requestFactory->createRequest('GET', self::BASE_URL.$pathAndQuery)
            ->withHeader('User-Agent', UserAgent::forManifest($this->ownManifest));

        try {
            $response = $this->httpClient->sendRequest($request);
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
        } catch (ClientExceptionInterface|\RuntimeException $exception) {
            throw new WorldartRequestException('Failed to reach World-Art.', 0, $exception);
        }

        if ($status !== 200) {
            throw new WorldartRequestException(\sprintf('World-Art responded with HTTP %d.', $status));
        }

        $page = WorldartPage::fromBytes($body);
        $target = $page->refreshTarget();
        if ($target !== null && parse_url($target, \PHP_URL_PATH) === self::NOT_CONNECT_PATH) {
            throw new WorldartRequestException('World-Art is unavailable.');
        }

        return $page;
    }
}
