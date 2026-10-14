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
 * from the settings. Every network call goes through the cross-process limiter. A card is read from
 * the 24-hour disk cache first (a hit does not touch the limiter) and is put there only when the
 * answer really is a card; "not found" answers are never remembered, because the site answers
 * "no such entry" and "the site is down" in the same way. Search is never cached. The body is
 * handed to {@see WorldartPage} as it came, in `windows-1251`.
 */
final class WorldartClient
{
    public const BASE_URL = 'http://www.world-art.ru';

    private const NOT_CONNECT_PATH = '/not_connect.html';
    private const ANIMATION_INDEX_PATH = '/animation/';
    private const FIRST_CARD_COVER = '#/animation/img/1000/1/1\.[a-z]+$#i';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly OwnManifestInterface $ownManifest,
        private readonly RequestLimiter $limiter,
        private readonly CardCache $cardCache,
    ) {
    }

    /**
     * A card page, or null when the site has no such card: a redirect to the "site is unavailable"
     * page or to the section index, the page of the first card instead of the requested one, or
     * any answer that is not a card. Nothing of that is cached.
     *
     * @param callable(): void|null $onHeartbeat called while waiting for a limiter slot
     *
     * @throws WorldartRequestException
     */
    public function fetchCard(int $id, ?callable $onHeartbeat = null): ?WorldartPage
    {
        if ($id < 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid World-Art id "%d".', $id));
        }

        $cached = $this->cardCache->get($id);
        if ($cached !== null) {
            $page = WorldartPage::fromBytes($cached);
            if ($page->isCard()) {
                return $page;
            }
        }

        $body = $this->download(\sprintf('/animation/animation.php?id=%d', $id), $onHeartbeat);
        $page = WorldartPage::fromBytes($body);
        if (!$this->isRequestedCard($page, $id)) {
            return null;
        }

        $this->cardCache->put($id, $body);

        return $page;
    }

    /**
     * The page of unmoderated short descriptions of an entry, or null when the site has no such
     * page: a redirect to the "site is unavailable" page or any answer without the page heading.
     * Nothing of that is cached. Goes through the same limiter and the same 24-hour disk cache as
     * the card, in a separate file.
     *
     * @param callable(): void|null $onHeartbeat called while waiting for a limiter slot
     *
     * @throws WorldartRequestException
     */
    public function fetchSynopsis(int $id, ?callable $onHeartbeat = null): ?WorldartPage
    {
        if ($id < 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid World-Art id "%d".', $id));
        }

        $cached = $this->cardCache->getSynopsis($id);
        if ($cached !== null) {
            $page = WorldartPage::fromBytes($cached);
            if ($page->isSynopsisPage()) {
                return $page;
            }
        }

        $body = $this->download(\sprintf('/animation/animation_update_synopsis.php?id=%d', $id), $onHeartbeat);
        $page = WorldartPage::fromBytes($body);
        if ($this->refreshPath($page) === self::NOT_CONNECT_PATH || !$page->isSynopsisPage()) {
            return null;
        }

        $this->cardCache->putSynopsis($id, $body);

        return $page;
    }

    /**
     * A search page for the query already encoded in `windows-1251`. A redirect to the "site is
     * unavailable" page is a failure, not an empty answer.
     *
     * @param callable(): void|null $onHeartbeat called while waiting for a limiter slot
     *
     * @throws WorldartRequestException
     */
    public function search(string $encodedQuery, ?callable $onHeartbeat = null): WorldartPage
    {
        $page = WorldartPage::fromBytes($this->download(
            '/search.php?public_search='.urlencode($encodedQuery).'&global_sector=animation',
            $onHeartbeat,
        ));
        if ($this->refreshPath($page) === self::NOT_CONNECT_PATH) {
            throw new WorldartRequestException('World-Art is unavailable.');
        }

        return $page;
    }

    private function isRequestedCard(WorldartPage $page, int $id): bool
    {
        $path = $this->refreshPath($page);
        if ($path === self::NOT_CONNECT_PATH || $path === self::ANIMATION_INDEX_PATH) {
            return false;
        }
        if (!$page->isCard()) {
            return false;
        }

        // the site used to answer an unknown id with the page of the first card
        $cover = $page->coverSrc();
        if ($id !== 1 && $cover !== null && preg_match(self::FIRST_CARD_COVER, (string) parse_url($cover, \PHP_URL_PATH)) === 1) {
            return false;
        }

        return true;
    }

    /**
     * The path of the `meta refresh` target, always with the leading slash.
     */
    private function refreshPath(WorldartPage $page): ?string
    {
        $target = $page->refreshTarget();
        if ($target === null) {
            return null;
        }
        $path = parse_url($target, \PHP_URL_PATH);
        if (!\is_string($path) || $path === '') {
            return null;
        }
        $path = '/'.ltrim($path, '/');

        return $path === '/animation' ? self::ANIMATION_INDEX_PATH : $path;
    }

    /**
     * @param callable(): void|null $onHeartbeat
     */
    private function download(string $pathAndQuery, ?callable $onHeartbeat): string
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

        return $body;
    }
}
