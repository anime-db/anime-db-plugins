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

namespace AnimeDb\Plugins\AnimedbShikimori\Http;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Thin client for Shikimori's REST API, used by
 * {@see \AnimeDb\Plugins\AnimedbShikimori\ShikimoriFiller::push()} (write endpoints, REST v2
 * `user_rates`) and by {@see \AnimeDb\Plugins\AnimedbShikimori\Widget\SimilarWidget} (read-only,
 * anonymous `similar` endpoint — REST v1, not covered by the GraphQL schema at all).
 * `POST /api/v2/user_rates` is create-only (Shikimori has no REST "upsert"), so a create call
 * against an already-tracked title fails; the caller is responsible for the find-or-create
 * sequence ({@see self::findUserRateId()} then {@see self::createUserRate()} or
 * {@see self::updateUserRate()}).
 *
 * The `user_rates` write endpoints require a Bearer token (the `user_rates` OAuth scope); unlike
 * {@see GraphQlClient}, those methods therefore take `$bearer` as a required parameter rather
 * than optional. {@see self::getSimilarAnimes()} is the one exception — Shikimori's `similar`
 * endpoint is public, so it calls the private {@see self::request()} helper with a null bearer
 * and no `Authorization` header is sent.
 *
 * Shares the *same* {@see RateLimiter} instance as {@see GraphQlClient} (constructor-injected,
 * not constructed here): Shikimori's 5rps/90rpm budget is enforced per IP across REST and
 * GraphQL together, so two independently-paced limiter instances would double-burst and risk a
 * ban.
 *
 * HTTP 401 is surfaced as {@see UnauthorizedHttpException}, the same type
 * {@see GraphQlClient::query()} throws, so {@see \AnimeDb\Plugins\AnimedbShikimori\Sync\ShikimoriAuthRetrier}
 * can retry either transport through a single catch clause.
 */
class ShikimoriRestClient
{
    private const DEFAULT_ENDPOINT = 'https://shikimori.io';
    private const USER_RATES_PATH = '/api/v2/user_rates';
    private const SIMILAR_PATH_FORMAT = '/api/animes/%s/similar';
    private const TARGET_TYPE_ANIME = 'Anime';
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_SUCCESS_STATUS_MIN = 200;
    private const HTTP_SUCCESS_STATUS_MAX_EXCLUSIVE = 300;
    private const USER_AGENT_FORMAT = 'AnimeDB %s/%s (+https://anime-db.org/)';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly SettingsStoreInterface $settings,
        private readonly RateLimiter $rateLimiter,
        private readonly OwnManifestInterface $ownManifest,
    ) {
    }

    /**
     * Finds the current user's existing `user_rate` id for an anime target, or null if the
     * title is not on the user's list yet.
     *
     * @throws UnauthorizedHttpException the request got HTTP 401 back
     * @throws RestRequestException      transport failure, another non-2xx status, or an
     *                                   unparseable response body
     */
    public function findUserRateId(string $bearer, string $userId, string $targetId): ?string
    {
        $query = http_build_query([
            'user_id' => $userId,
            'target_id' => $targetId,
            'target_type' => self::TARGET_TYPE_ANIME,
        ]);

        $data = $this->request('GET', self::USER_RATES_PATH.'?'.$query, null, $bearer);
        $first = \is_array($data) ? ($data[0] ?? null) : null;
        $id = \is_array($first) ? ($first['id'] ?? null) : null;

        return \is_string($id) || \is_int($id) ? (string) $id : null;
    }

    /**
     * Creates a new `user_rate` for an anime target. `user_id` is required by Shikimori's REST
     * v2 `create` action (unlike `update`, it is not inferred from the Bearer token). `$episodes`
     * (watched episode count) is omitted from the body entirely when null, rather than sent as
     * `0` — the caller is responsible for that distinction (see
     * {@see \AnimeDb\PluginContracts\Sync\SyncItem::$watchedEpisodes}).
     *
     * @return array<string, mixed> the decoded response body (the created `user_rate`), used by
     *                               the caller to read back Shikimori's confirmed `updated_at`
     *                               and `episodes`
     *
     * @throws UnauthorizedHttpException the request got HTTP 401 back
     * @throws RestRequestException      transport failure, another non-2xx status, or an
     *                                   unparseable response body
     */
    public function createUserRate(string $bearer, string $userId, string $targetId, string $status, ?int $episodes = null): array
    {
        $userRate = [
            'user_id' => $userId,
            'target_id' => $targetId,
            'target_type' => self::TARGET_TYPE_ANIME,
            'status' => $status,
        ];

        if ($episodes !== null) {
            $userRate['episodes'] = $episodes;
        }

        $data = $this->request('POST', self::USER_RATES_PATH, ['user_rate' => $userRate], $bearer);

        return \is_array($data) ? $data : [];
    }

    /**
     * Updates the status (and, when given, the watched episode count) of an existing `user_rate`.
     *
     * @return array<string, mixed> the decoded response body (the updated `user_rate`), used by
     *                               the caller to read back Shikimori's confirmed `updated_at`
     *                               and `episodes`
     *
     * @throws UnauthorizedHttpException the request got HTTP 401 back
     * @throws RestRequestException      transport failure, another non-2xx status, or an
     *                                   unparseable response body
     */
    public function updateUserRate(string $bearer, string $rateId, string $status, ?int $episodes = null): array
    {
        $userRate = ['status' => $status];

        if ($episodes !== null) {
            $userRate['episodes'] = $episodes;
        }

        $data = $this->request('PATCH', self::USER_RATES_PATH.'/'.rawurlencode($rateId), ['user_rate' => $userRate], $bearer);

        return \is_array($data) ? $data : [];
    }

    /**
     * Lists anime similar to $externalId, per Shikimori's public (unauthenticated)
     * `/api/animes/:id/similar` endpoint — this data is not exposed by the GraphQL schema.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RestRequestException transport failure, a non-2xx status, or an unparseable
     *                              response body
     */
    public function getSimilarAnimes(string $externalId): array
    {
        $data = $this->request('GET', \sprintf(self::SIMILAR_PATH_FORMAT, rawurlencode($externalId)), null, null);

        // array_values(): ответ приходит из json_decode() и ключи у него произвольные, а метод
        // объявляет список. Нормализуем здесь, а не в вызывающем коде, чтобы обещание в
        // сигнатуре было правдой на выходе из этого метода.
        return \is_array($data) ? array_values($data) : [];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param string|null               $bearer sent as `Authorization: Bearer <token>` when
     *                                          given, omitted entirely for a public endpoint
     *                                          (see {@see self::getSimilarAnimes()})
     */
    private function request(string $method, string $path, ?array $body, ?string $bearer): mixed
    {
        $this->rateLimiter->acquire();

        $endpoint = rtrim($this->resolveEndpoint(), '/').$path;
        $request = $this->requestFactory->createRequest($method, $endpoint)
            ->withHeader('User-Agent', $this->userAgent())
            ->withHeader('Accept', 'application/json');

        if ($bearer !== null) {
            $request = $request->withHeader('Authorization', 'Bearer '.$bearer);
        }

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($body, \JSON_THROW_ON_ERROR)));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new RestRequestException('Failed to reach Shikimori REST API.', 0, $exception);
        }

        $status = $response->getStatusCode();

        if ($status === self::HTTP_UNAUTHORIZED) {
            throw new UnauthorizedHttpException('Shikimori REST API responded with HTTP 401.');
        }

        if ($status < self::HTTP_SUCCESS_STATUS_MIN || $status >= self::HTTP_SUCCESS_STATUS_MAX_EXCLUSIVE) {
            throw new RestRequestException(\sprintf('Shikimori REST API responded with HTTP %d.', $status));
        }

        return self::decodeBody($response);
    }

    private function resolveEndpoint(): string
    {
        $endpoint = $this->settings->read()['api_endpoint'] ?? null;

        return \is_string($endpoint) && $endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINT;
    }

    private function userAgent(): string
    {
        return \sprintf(self::USER_AGENT_FORMAT, $this->ownManifest->id(), $this->ownManifest->version());
    }

    private static function decodeBody(ResponseInterface $response): mixed
    {
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return null;
        }

        try {
            return json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RestRequestException('Shikimori REST API returned invalid JSON.', 0, $exception);
        }
    }
}
