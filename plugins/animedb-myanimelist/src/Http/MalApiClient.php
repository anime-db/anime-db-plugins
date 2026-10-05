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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Http;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Thin REST/JSON client for the MyAnimeList API v2, built solely on PSR-18/PSR-17
 * (`ClientInterface`, `RequestFactoryInterface`, `StreamFactoryInterface`) — no Guzzle/league,
 * no Symfony HTTP types (those must not appear in a plugin bundle that ships without its own
 * `vendor/`, see the project's `.claude-docs/gotchas.md`). `StreamFactoryInterface` is used
 * only by the body-carrying write methods ({@see self::updateListStatus()}); {@see self::get()}
 * never builds a body, every call it makes is a GET request with no body.
 *
 * The API's domain is fixed to {@see self::BASE_URL} — there is no per-plugin setting for it,
 * unlike Shikimori's `api_endpoint`: MyAnimeList has a single official endpoint, and there is
 * no reason a plugin user would point this client somewhere else.
 *
 * Anonymous by default (this plugin's phase 1 use — {@see \AnimeDb\Plugins\AnimedbMyanimelist\MalFiller::find()}
 * on the catalog import path): no `Authorization` header is sent unless the caller passes
 * `$bearer` explicitly to {@see self::get()}. That token is never read from any store by this
 * class itself — a future sync caller resolves it per call from the OAuth client, so an
 * anonymous catalog import never leaks a user's token. An anonymous request instead carries
 * `X-MAL-CLIENT-ID` ({@see self::CLIENT_ID}), required by MyAnimeList on every unauthenticated
 * call (confirmed live: a request without it gets HTTP 403). The same constant is meant to be
 * reused by this plugin's future OAuth client (task #179) rather than duplicated as a second
 * literal.
 *
 * Rate limiting is this plugin's own responsibility (the host does not throttle plugin HTTP
 * calls): {@see RateLimiter} paces every request, and a 429 response is treated as a signal to
 * back off and retry (bounded), not as an immediate error — the host's bulk filler runs a
 * card-fetching method in a scan loop and silently turns any exception into an empty
 * placeholder card, so throwing on an ordinary rate-limit hit would fill a large catalog
 * import with blank cards instead of just taking longer.
 */
class MalApiClient
{
    /**
     * Public OAuth client id of this plugin's registered MyAnimeList application. The
     * repository is public, so this value is necessarily public too — MyAnimeList issues no
     * client secret for this application type (a "public client"), so there is nothing else to
     * protect.
     */
    public const CLIENT_ID = 'e50e8abf8fc0c8f6f8baa5e2e9378f9e';

    private const BASE_URL = 'https://api.myanimelist.net/v2';
    private const MAX_RETRIES = 5;
    private const MAX_RETRY_WAIT_SECONDS = 60.0;
    private const DEFAULT_RETRY_AFTER_SECONDS = 1.0;
    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_NOT_FOUND = 404;
    private const HTTP_SUCCESS_STATUS_MIN = 200;
    private const HTTP_SUCCESS_STATUS_MAX_EXCLUSIVE = 300;
    private const LIST_STATUS_PATH_FORMAT = '/anime/%s/my_list_status';
    private const ANIMELIST_PATH = '/users/@me/animelist';
    private const ANIMELIST_FIELDS = 'list_status';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly RateLimiter $rateLimiter,
        private readonly OwnManifestInterface $ownManifest,
    ) {
    }

    /**
     * Executes a GET request against the MyAnimeList API and returns the decoded JSON body.
     *
     * @param array<string, scalar> $query
     * @param callable(): void|null $onHeartbeat called between internal steps (rate-limit
     *                                           pauses, 429 retries) so a long-running caller
     *                                           can refresh a background job lock
     * @param string|null           $bearer      when given, sent as `Authorization: Bearer
     *                                           <token>` instead of the anonymous
     *                                           `X-MAL-CLIENT-ID` header. Never resolved by this
     *                                           class itself — the caller decides per call
     *                                           whether the request is anonymous or authed (see
     *                                           class doc)
     *
     * @return array<string, mixed>
     *
     * @throws UnauthorizedHttpException the request got HTTP 401 back (only relevant when
     *                                   $bearer was passed)
     * @throws NotFoundHttpException     the request got HTTP 404 back — the requested resource
     *                                   does not exist
     * @throws MalRequestException       transport failure, another non-2xx/non-401/non-404
     *                                   status, invalid JSON, or the 429 retry budget was
     *                                   exhausted
     */
    public function get(string $path, array $query = [], ?callable $onHeartbeat = null, ?string $bearer = null): array
    {
        return $this->sendWithRetry(fn (): ResponseInterface => $this->send($path, $query, $bearer), $onHeartbeat, true);
    }

    /**
     * Fetches one page of the authenticated user's anime list.
     *
     * The request is built from its own `$offset`/`$limit`; the URL the API returns in
     * `paging.next` is never followed (the Bearer token must not end up going to whatever host
     * happens to appear in that field) — only whether the `next` key is present is read, as the
     * signal to keep paginating. An empty page (zero items) always means "no more pages", even
     * when `paging.next` is present, to rule out an infinite loop. A page shorter than `$limit`
     * is NOT by itself a stop signal: the API can filter items out after paginating, so a short
     * page is not necessarily the last one.
     *
     * `nsfw=true` is mandatory, not optional: without it MyAnimeList silently drops 18+ titles
     * from the list, which would otherwise look to the host like those titles were removed from
     * the source.
     *
     * @return array{items: list<array<string, mixed>>, hasNext: bool} `items` are the page's
     *                                                                  raw list entries
     *                                                                  (`node`/`list_status`);
     *                                                                  the `paging.next` URL
     *                                                                  itself is never returned
     *
     * @throws UnauthorizedHttpException the request got HTTP 401 back
     * @throws MalRequestException       transport failure, another non-2xx/non-401 status,
     *                                   invalid JSON, or the 429 retry budget was exhausted
     */
    public function fetchAnimeListPage(string $bearer, int $offset, int $limit): array
    {
        $data = $this->get(self::ANIMELIST_PATH, [
            'fields' => self::ANIMELIST_FIELDS,
            'limit' => $limit,
            'offset' => $offset,
            'nsfw' => 'true',
        ], null, $bearer);

        $items = \is_array($data['data'] ?? null) ? array_values($data['data']) : [];
        $paging = \is_array($data['paging'] ?? null) ? $data['paging'] : [];

        return [
            'items' => $items,
            'hasNext' => $items !== [] && \array_key_exists('next', $paging),
        ];
    }

    /**
     * Writes the authenticated user's watch status for a single title. `PATCH
     * /anime/{anime_id}/my_list_status` is an upsert on MyAnimeList's side — the same request
     * both adds a title not yet on the list and updates one that already is, so this method,
     * unlike {@see \AnimeDb\Plugins\AnimedbShikimori\Http\ShikimoriRestClient}'s `user_rates`
     * writes, never needs a find-or-create sequence.
     *
     * `is_rewatching` is always sent as `false` — a constant part of the request body, not a
     * parameter — because the contract has no status a caller could derive a rewatch flag from.
     * Leaving a stale `is_rewatching: true` on MyAnimeList would survive a `completed` push and
     * make the next read come back as `watching` (see
     * {@see \AnimeDb\Plugins\AnimedbMyanimelist\Mapping\SyncStatusMapper::fromMal()}), silently
     * reverting the status this method was just asked to set.
     *
     * `num_watched_episodes` is included only when `$watchedEpisodes` is not null — even when
     * it is `0` — the caller is responsible for that distinction (not reporting an episode
     * count at all is different from reporting zero watched episodes).
     *
     * @return array<string, mixed> the decoded response body — MyAnimeList returns the updated
     *                              `my_list_status` object directly, with `updated_at` and
     *                              `num_episodes_watched` among its fields
     *
     * @throws \InvalidArgumentException $animeId is not a bare positive integer — rejected here
     *                                    rather than left to the caller, since a value such as
     *                                    `1/../x` or `1?x=y` would otherwise change the path or
     *                                    query of an authorized request
     * @throws UnauthorizedHttpException the request got HTTP 401 back
     * @throws MalRequestException       transport failure, another non-2xx/non-401 status, or
     *                                   invalid JSON
     */
    public function updateListStatus(string $bearer, string $animeId, string $status, ?int $watchedEpisodes): array
    {
        if (preg_match('/^[1-9]\d*\z/', $animeId) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid MyAnimeList anime id "%s".', $animeId));
        }

        $body = [
            'status' => $status,
            'is_rewatching' => 'false',
        ];

        if ($watchedEpisodes !== null) {
            $body['num_watched_episodes'] = (string) $watchedEpisodes;
        }

        return $this->sendForm(\sprintf(self::LIST_STATUS_PATH_FORMAT, $animeId), $body, $bearer);
    }

    /**
     * Removes a single title from the authenticated user's list: `DELETE
     * /anime/{anime_id}/my_list_status`. Idempotent — HTTP 404 ("not on the list") is a normal
     * outcome and returns quietly. HTTP 429 is backed off and retried like every other call
     * ({@see self::sendWithRetry()}); 5xx and transport failures are not retried here. The
     * success response may carry an empty body, which is not an error.
     *
     * @throws \InvalidArgumentException $animeId is not a bare positive integer (see
     *                                    {@see self::updateListStatus()})
     * @throws UnauthorizedHttpException the request got HTTP 401 back
     * @throws MalRequestException       transport failure, another non-2xx/non-401/non-404
     *                                   status, or the 429 retry budget was exhausted
     */
    public function deleteListStatus(string $bearer, string $animeId): void
    {
        if (preg_match('/^[1-9]\d*\z/', $animeId) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid MyAnimeList anime id "%s".', $animeId));
        }

        try {
            $this->sendWithRetry(fn (): ResponseInterface => $this->sendDelete(\sprintf(self::LIST_STATUS_PATH_FORMAT, $animeId), $bearer), null, true, true);
        } catch (NotFoundHttpException) {
            // Not on the list: nothing to remove.
        }
    }

    private function sendDelete(string $path, string $bearer): ResponseInterface
    {
        $request = $this->requestFactory->createRequest('DELETE', self::BASE_URL.$path)
            ->withHeader('User-Agent', UserAgent::forManifest($this->ownManifest))
            ->withHeader('Authorization', 'Bearer '.$bearer);

        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new MalRequestException('Failed to reach MyAnimeList API.', 0, $exception);
        }
    }

    /**
     * @param array<string, scalar> $query
     */
    private function send(string $path, array $query, ?string $bearer): ResponseInterface
    {
        $url = self::BASE_URL.$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('User-Agent', UserAgent::forManifest($this->ownManifest));

        $request = $bearer !== null
            ? $request->withHeader('Authorization', 'Bearer '.$bearer)
            : $request->withHeader('X-MAL-CLIENT-ID', self::CLIENT_ID);

        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new MalRequestException('Failed to reach MyAnimeList API.', 0, $exception);
        }
    }

    /**
     * Sends a `PATCH` request with an `application/x-www-form-urlencoded` body — used only by
     * {@see self::updateListStatus()}. Retries on HTTP 429 the same way {@see self::get()} does
     * ({@see self::sendWithRetry()}): `PATCH /anime/{id}/my_list_status` is an upsert, so
     * repeating the same request is safe, and the request body is rebuilt from scratch on every
     * attempt rather than reusing a stream already consumed by a previous try.
     *
     * @param array<string, string> $body
     *
     * @return array<string, mixed>
     */
    private function sendForm(string $path, array $body, string $bearer): array
    {
        return $this->sendWithRetry(fn (): ResponseInterface => $this->sendPatch($path, $body, $bearer), null, false);
    }

    /**
     * @param array<string, string> $body
     */
    private function sendPatch(string $path, array $body, string $bearer): ResponseInterface
    {
        $request = $this->requestFactory->createRequest('PATCH', self::BASE_URL.$path)
            ->withHeader('User-Agent', UserAgent::forManifest($this->ownManifest))
            ->withHeader('Authorization', 'Bearer '.$bearer)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream(http_build_query($body)));

        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new MalRequestException('Failed to reach MyAnimeList API.', 0, $exception);
        }
    }

    /**
     * Shared retry/response-handling loop for {@see self::get()} and {@see self::sendForm()}:
     * paces every attempt through {@see RateLimiter::acquire()}, backs off and retries
     * (bounded) on HTTP 429 via {@see RateLimiter::sleep()}, and maps 401/404/other non-2xx
     * statuses to the client's exceptions. `$send` is invoked again on every retry so a
     * body-carrying caller rebuilds its request (and stream) from scratch rather than resending
     * an already-consumed one.
     *
     * @param callable(): ResponseInterface $send
     * @param callable(): void|null         $onHeartbeat
     * @param bool                          $allowEmptyBody an empty 2xx body yields `[]` instead of
     *                                                      an invalid-JSON error (DELETE only)
     *
     * @return array<string, mixed>
     *
     * @throws UnauthorizedHttpException the request got HTTP 401 back
     * @throws NotFoundHttpException     $notFoundAsException is true and the request got HTTP
     *                                   404 back
     * @throws MalRequestException       transport failure, another non-2xx status, invalid
     *                                   JSON, or the 429 retry budget was exhausted
     */
    private function sendWithRetry(callable $send, ?callable $onHeartbeat, bool $notFoundAsException, bool $allowEmptyBody = false): array
    {
        $attempt = 0;
        $waitedSeconds = 0.0;

        while (true) {
            if ($onHeartbeat !== null) {
                $onHeartbeat();
            }
            $this->rateLimiter->acquire();

            $response = $send();
            $status = $response->getStatusCode();

            if ($status === self::HTTP_TOO_MANY_REQUESTS) {
                $retryAfter = self::parseRetryAfter($response);
                $waitedSeconds += $retryAfter;
                ++$attempt;

                if ($attempt > self::MAX_RETRIES || $waitedSeconds > self::MAX_RETRY_WAIT_SECONDS) {
                    throw new MalRequestException(\sprintf('MyAnimeList API rate limit exceeded after %d retries.', $attempt - 1));
                }

                if ($onHeartbeat !== null) {
                    $onHeartbeat();
                }
                $this->rateLimiter->sleep($retryAfter);
                continue;
            }

            if ($status === self::HTTP_UNAUTHORIZED) {
                throw new UnauthorizedHttpException('MyAnimeList API responded with HTTP 401.');
            }

            if ($notFoundAsException && $status === self::HTTP_NOT_FOUND) {
                throw new NotFoundHttpException('MyAnimeList API responded with HTTP 404.');
            }

            if ($status < self::HTTP_SUCCESS_STATUS_MIN || $status >= self::HTTP_SUCCESS_STATUS_MAX_EXCLUSIVE) {
                throw new MalRequestException(\sprintf('MyAnimeList API responded with HTTP %d.', $status));
            }

            return self::decodeBody($response, $allowEmptyBody);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeBody(ResponseInterface $response, bool $allowEmptyBody): array
    {
        $raw = (string) $response->getBody();
        if ($allowEmptyBody && trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MalRequestException('MyAnimeList API returned invalid JSON.', 0, $exception);
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private static function parseRetryAfter(ResponseInterface $response): float
    {
        $header = $response->getHeaderLine('Retry-After');
        if ($header === '') {
            return self::DEFAULT_RETRY_AFTER_SECONDS;
        }

        if (is_numeric($header)) {
            return max(0.0, (float) $header);
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? self::DEFAULT_RETRY_AFTER_SECONDS : max(0.0, (float) ($timestamp - time()));
    }
}
