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

/**
 * Thin REST/JSON client for the MyAnimeList API v2, built solely on PSR-18/PSR-17
 * (`ClientInterface`, `RequestFactoryInterface`) — no Guzzle/league, no Symfony HTTP types
 * (those must not appear in a plugin bundle that ships without its own `vendor/`, see the
 * project's `.claude-docs/gotchas.md`). A `StreamFactoryInterface` is deliberately not
 * constructor-injected here: every call {@see self::get()} makes is a GET request with no
 * body, so there is nothing to build a stream for; a future POST-based call (OAuth token
 * exchange) takes its own `StreamFactoryInterface` dependency when it is written, rather than
 * this class carrying an unused one now.
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
    private const HTTP_SUCCESS_STATUS_MIN = 200;
    private const HTTP_SUCCESS_STATUS_MAX_EXCLUSIVE = 300;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
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
     * @throws MalRequestException       transport failure, another non-2xx status, invalid JSON,
     *                                   or the 429 retry budget was exhausted
     */
    public function get(string $path, array $query = [], ?callable $onHeartbeat = null, ?string $bearer = null): array
    {
        $attempt = 0;
        $waitedSeconds = 0.0;

        while (true) {
            if ($onHeartbeat !== null) {
                $onHeartbeat();
            }
            $this->rateLimiter->acquire();

            $response = $this->send($path, $query, $bearer);
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

            if ($status < self::HTTP_SUCCESS_STATUS_MIN || $status >= self::HTTP_SUCCESS_STATUS_MAX_EXCLUSIVE) {
                throw new MalRequestException(\sprintf('MyAnimeList API responded with HTTP %d.', $status));
            }

            return self::decodeBody($response);
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
     * @return array<string, mixed>
     */
    private static function decodeBody(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
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
