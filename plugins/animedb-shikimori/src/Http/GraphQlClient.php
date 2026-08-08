<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
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
 * Thin POST-only GraphQL client for the Shikimori API, built solely on PSR-18/PSR-17 (no
 * Guzzle/league, no Symfony HTTP types — those must not appear in a plugin bundle that ships
 * without its own `vendor/`, see the project's `.claude-docs/gotchas.md`).
 *
 * Anonymous by default (this plugin's phase 1 use — find()/findById() on the catalog import
 * path): no `Authorization` header is sent unless the caller passes `$bearer` explicitly to
 * {@see self::query()}. That token is never read from settings by this class itself — a sync
 * caller ({@see \AnimeDb\Plugins\AnimedbShikimori\ShikimoriFiller::pull()}) resolves it per call
 * from the OAuth client, so an anonymous catalog import never leaks a user's token. The shared
 * host PSR-18 client does not guarantee a `User-Agent`, and Shikimori bans by IP without one, so
 * every request sets it explicitly.
 *
 * Rate limiting is this plugin's own responsibility (the host does not throttle plugin HTTP
 * calls): {@see RateLimiter} paces every request to Shikimori's 5 rps / 90 rpm, and a 429
 * response is treated as a signal to back off and retry (bounded), not as an immediate error —
 * the host's bulk filler runs `findById()` in a scan loop and silently turns any exception into
 * an empty placeholder card, so throwing on an ordinary rate-limit hit would fill a large
 * catalog import with blank cards instead of just taking longer.
 */
class GraphQlClient
{
    private const DEFAULT_ENDPOINT = 'https://shikimori.io';
    private const MAX_RETRIES = 5;
    private const MAX_RETRY_WAIT_SECONDS = 60.0;
    private const DEFAULT_RETRY_AFTER_SECONDS = 1.0;
    private const HTTP_TOO_MANY_REQUESTS = 429;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_SUCCESS_STATUS_MIN = 200;
    private const HTTP_SUCCESS_STATUS_MAX_EXCLUSIVE = 300;
    private const USER_AGENT_FORMAT = 'AnimeDB %s/%s (+https://anime-db.org/)';
    private const UNKNOWN_GRAPHQL_ERROR_MESSAGE = 'unknown error';

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
     * Executes a GraphQL query and returns its `data` payload.
     *
     * @param array<string, mixed>  $variables
     * @param callable(): void|null $onHeartbeat called between internal steps (rate-limit
     *                                           pauses, 429 retries) so a long-running caller
     *                                           can refresh a background job lock
     * @param string|null           $bearer      when given, sent as `Authorization: Bearer
     *                                           <token>`. Never resolved by this class itself —
     *                                           the caller decides per call whether the request
     *                                           is anonymous or authed (see class doc)
     *
     * @return array<string, mixed>
     *
     * @throws UnauthorizedHttpException the request got HTTP 401 back (only relevant when
     *                                   $bearer was passed)
     * @throws GraphQlRequestException   transport failure, another non-2xx status, non-empty
     *                                   GraphQL `errors[]`, or the 429 retry budget was exhausted
     */
    public function query(string $query, array $variables = [], ?callable $onHeartbeat = null, ?string $bearer = null): array
    {
        $attempt = 0;
        $waitedSeconds = 0.0;

        while (true) {
            if ($onHeartbeat !== null) {
                $onHeartbeat();
            }
            $this->rateLimiter->acquire();

            $response = $this->send($query, $variables, $bearer);
            $status = $response->getStatusCode();

            if ($status === self::HTTP_TOO_MANY_REQUESTS) {
                $retryAfter = self::parseRetryAfter($response);
                $waitedSeconds += $retryAfter;
                ++$attempt;

                if ($attempt > self::MAX_RETRIES || $waitedSeconds > self::MAX_RETRY_WAIT_SECONDS) {
                    throw new GraphQlRequestException(\sprintf('Shikimori GraphQL API rate limit exceeded after %d retries.', $attempt - 1));
                }

                if ($onHeartbeat !== null) {
                    $onHeartbeat();
                }
                $this->rateLimiter->sleep($retryAfter);
                continue;
            }

            if ($status === self::HTTP_UNAUTHORIZED) {
                throw new UnauthorizedHttpException('Shikimori GraphQL API responded with HTTP 401.');
            }

            if ($status < self::HTTP_SUCCESS_STATUS_MIN || $status >= self::HTTP_SUCCESS_STATUS_MAX_EXCLUSIVE) {
                throw new GraphQlRequestException(\sprintf('Shikimori GraphQL API responded with HTTP %d.', $status));
            }

            $payload = self::decodeBody($response);

            if (!empty($payload['errors']) && \is_array($payload['errors'])) {
                throw new GraphQlRequestException(\sprintf('Shikimori GraphQL API returned errors: %s', self::formatErrors($payload['errors'])));
            }

            return \is_array($payload['data'] ?? null) ? $payload['data'] : [];
        }
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function send(string $query, array $variables, ?string $bearer): ResponseInterface
    {
        $endpoint = rtrim($this->resolveEndpoint(), '/').'/api/graphql';
        $body = $this->streamFactory->createStream(
            json_encode(['query' => $query, 'variables' => $variables], \JSON_THROW_ON_ERROR),
        );

        $request = $this->requestFactory->createRequest('POST', $endpoint)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('User-Agent', $this->userAgent())
            ->withBody($body);

        if ($bearer !== null) {
            $request = $request->withHeader('Authorization', 'Bearer '.$bearer);
        }

        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new GraphQlRequestException('Failed to reach Shikimori GraphQL API.', 0, $exception);
        }
    }

    private function resolveEndpoint(): string
    {
        $endpoint = $this->settings->read()['api_endpoint'] ?? null;

        return \is_string($endpoint) && $endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINT;
    }

    /**
     * `AnimeDB` is the registered OAuth application name at Shikimori; Shikimori requires it to
     * be present in the `User-Agent` or the caller's IP gets banned, and the same identity is
     * needed for the OAuth requests planned in a later phase. The id and version come from the
     * host-injected {@see OwnManifestInterface} rather than being duplicated as literals.
     */
    private function userAgent(): string
    {
        return \sprintf(self::USER_AGENT_FORMAT, $this->ownManifest->id(), $this->ownManifest->version());
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeBody(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new GraphQlRequestException('Shikimori GraphQL API returned invalid JSON.', 0, $exception);
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<mixed> $errors
     */
    private static function formatErrors(array $errors): string
    {
        $messages = array_map(
            static fn (mixed $error): string => \is_array($error) && \is_string($error['message'] ?? null)
                ? $error['message']
                : self::UNKNOWN_GRAPHQL_ERROR_MESSAGE,
            $errors,
        );

        return implode('; ', $messages);
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
