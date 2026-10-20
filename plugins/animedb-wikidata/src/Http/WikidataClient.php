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

namespace AnimeDb\Plugins\AnimedbWikidata\Http;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Client of the Wikidata API, built on PSR-18/PSR-17 only; HTTPS, no API key.
 *
 * Requests go one after another through the cross-process limiter (at most one per second for the
 * whole plugin). Every request carries `maxlag=5` and the User-Agent of {@see UserAgent}. The
 * transport timeout ({@see self::TIMEOUT_SECONDS}) belongs to the host-preconfigured PSR-18
 * client, which is the only place PSR-18 lets it be set. There are no retries: a `maxlag` answer,
 * a network failure, a non-200 status, a broken JSON body or any other API error is a
 * {@see WikidataRequestException}, and the next enrichment simply tries again. "Not found" is null.
 */
final class WikidataClient
{
    public const BASE_URL = 'https://www.wikidata.org/w/api.php';
    public const TIMEOUT_SECONDS = 10;
    public const MAXLAG_SECONDS = 5;

    public const PROPERTY_EXTERNAL_ID_IMDB = 'P345';
    public const PROPERTY_EXTERNAL_ID_KINOPOISK = 'P2603';

    private const LANGUAGES = 'ja|en|ru';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly OwnManifestInterface $ownManifest,
        private readonly RequestLimiter $limiter,
    ) {
    }

    /**
     * The id (`Q…`) of the one item that carries `$value` in the external id property `$property`
     * (`P345` for IMDb, `P2603` for Kinopoisk), or null when there is no such item or when there are
     * several: a bridge must not guess between titles.
     *
     * @param callable(): void|null $onHeartbeat called while waiting for a limiter slot
     *
     * @throws WikidataRequestException
     */
    public function findItemByProperty(string $property, string $value, ?callable $onHeartbeat = null): ?string
    {
        if (preg_match('/^P[1-9]\d*\z/', $property) !== 1 || preg_match('/^[A-Za-z0-9]+\z/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid Wikidata property or value.');
        }

        $data = $this->call([
            'action' => 'query',
            'list' => 'search',
            'srsearch' => \sprintf('haswbstatement:%s=%s', $property, $value),
        ], $onHeartbeat);

        $hits = $data['query']['search'] ?? null;
        if (!\is_array($hits)) {
            throw new WikidataRequestException('Unexpected Wikidata search response.');
        }

        $ids = [];
        foreach ($hits as $hit) {
            $title = \is_array($hit) ? ($hit['title'] ?? null) : null;
            if (!\is_string($title) || preg_match('/^Q[1-9]\d*\z/', $title) !== 1) {
                throw new WikidataRequestException('Unexpected Wikidata search response.');
            }
            $ids[] = $title;
        }

        return \count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * The entity (`labels` in ja/en/ru and `claims`) as decoded by the API, or null when the item
     * does not exist.
     *
     * @param callable(): void|null $onHeartbeat called while waiting for a limiter slot
     *
     * @return array<string, mixed>|null
     *
     * @throws WikidataRequestException
     */
    public function fetchEntity(string $id, ?callable $onHeartbeat = null): ?array
    {
        if (preg_match('/^Q[1-9]\d*\z/', $id) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid Wikidata item id "%s".', $id));
        }

        $data = $this->call([
            'action' => 'wbgetentities',
            'ids' => $id,
            'props' => 'claims|labels',
            'languages' => self::LANGUAGES,
        ], $onHeartbeat);

        $entity = $data['entities'][$id] ?? null;
        if (!\is_array($entity)) {
            throw new WikidataRequestException('Unexpected Wikidata entity response.');
        }
        if (isset($entity['missing'])) {
            return null;
        }

        return $entity;
    }

    /**
     * @param array<string, string> $params
     * @param callable(): void|null $onHeartbeat
     *
     * @return array<string, mixed>
     */
    private function call(array $params, ?callable $onHeartbeat): array
    {
        $this->limiter->acquire($onHeartbeat);

        $query = http_build_query($params + ['format' => 'json', 'maxlag' => (string) self::MAXLAG_SECONDS], '', '&', \PHP_QUERY_RFC3986);
        $request = $this->requestFactory->createRequest('GET', self::BASE_URL.'?'.$query)
            ->withHeader('User-Agent', UserAgent::forManifest($this->ownManifest))
            ->withHeader('Accept', 'application/json');

        try {
            $response = $this->httpClient->sendRequest($request);
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
        } catch (ClientExceptionInterface|\RuntimeException $exception) {
            throw new WikidataRequestException('Failed to reach Wikidata.', 0, $exception);
        }

        if ($status !== 200) {
            throw new WikidataRequestException(\sprintf('Wikidata responded with HTTP %d.', $status));
        }

        try {
            $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new WikidataRequestException('Wikidata responded with a broken JSON body.', 0, $exception);
        }
        if (!\is_array($data)) {
            throw new WikidataRequestException('Wikidata responded with an unexpected JSON body.');
        }

        if (isset($data['error'])) {
            $code = \is_array($data['error']) && \is_string($data['error']['code'] ?? null) ? $data['error']['code'] : 'unknown';

            throw new WikidataRequestException(\sprintf('Wikidata API error "%s".', $code));
        }

        return $data;
    }
}
