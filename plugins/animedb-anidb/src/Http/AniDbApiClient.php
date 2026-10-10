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

namespace AnimeDb\Plugins\AnimedbAnidb\Http;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Client of the AniDB HTTP API (`request=anime`), built on PSR-18/PSR-17 only.
 *
 * The API base and the client identity are constants: the API has no TLS and a single endpoint,
 * so nothing is read from the settings. A card is served from the 24-hour disk cache when
 * possible; otherwise the ban guard is checked, a limiter slot is taken and only then the
 * request is sent. The body is always gzip-compressed by AniDB, so it is unpacked by its magic
 * bytes rather than by headers, and parsed without network access or external entities.
 */
final class AniDbApiClient
{
    public const BASE_URL = 'http://api.anidb.net:9001/httpapi';
    public const CLIENT = 'animedbplugin';
    public const CLIENT_VERSION = 1;
    public const PROTOCOL_VERSION = 1;

    /** Unpacked card cap; real cards are well under 1 MB. */
    private const MAX_XML_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly OwnManifestInterface $ownManifest,
        private readonly RequestLimiter $limiter,
        private readonly BanGuard $banGuard,
        private readonly CardCache $cache,
    ) {
    }

    /**
     * @param callable(): void|null $onHeartbeat called while waiting for a limiter slot
     *
     * @throws NotFoundHttpException  there is no such card
     * @throws AniDbRequestException  any other failure
     */
    public function fetchAnime(int $aid, ?callable $onHeartbeat = null): \SimpleXMLElement
    {
        if ($aid < 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid AniDB anime id "%d".', $aid));
        }

        $cached = $this->cache->get($aid);
        if ($cached !== null) {
            $card = self::parse($cached);
            if ($card !== null && $card->getName() === 'anime') {
                return $card;
            }
        }

        $this->banGuard->assertNotBanned();
        $this->limiter->acquire($onHeartbeat);

        $xml = $this->download($aid);
        $card = self::parse($xml);
        if ($card === null) {
            throw new AniDbRequestException('AniDB API returned invalid XML.');
        }

        if ($card->getName() === 'error') {
            $this->throwApiError(trim((string) $card));
        }
        if ($card->getName() !== 'anime') {
            throw new AniDbRequestException('AniDB API returned an unexpected document.');
        }

        $this->cache->put($aid, $xml);

        return $card;
    }

    private function throwApiError(string $message): never
    {
        if (stripos($message, 'banned') !== false) {
            $this->banGuard->markBanned();

            throw new AniDbRequestException('AniDB API reports a ban: '.$message);
        }
        if (stripos($message, 'not found') !== false) {
            throw new NotFoundHttpException('AniDB API: '.$message);
        }

        throw new AniDbRequestException('AniDB API error: '.$message);
    }

    private function download(int $aid): string
    {
        $url = self::BASE_URL.'?'.http_build_query([
            'request' => 'anime',
            'client' => self::CLIENT,
            'clientver' => self::CLIENT_VERSION,
            'protover' => self::PROTOCOL_VERSION,
            'aid' => $aid,
        ]);
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('User-Agent', UserAgent::forManifest($this->ownManifest));

        try {
            $response = $this->httpClient->sendRequest($request);
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
        } catch (ClientExceptionInterface|\RuntimeException $exception) {
            throw new AniDbRequestException('Failed to reach AniDB API.', 0, $exception);
        }

        if ($status < 200 || $status >= 300) {
            throw new AniDbRequestException(\sprintf('AniDB API responded with HTTP %d.', $status));
        }

        if (str_starts_with($body, "\x1f\x8b")) {
            $unpacked = @gzdecode($body, self::MAX_XML_BYTES);
            if ($unpacked === false) {
                throw new AniDbRequestException('AniDB API returned a corrupt gzip body.');
            }
            $body = $unpacked;
        }

        return $body;
    }

    private static function parse(string $xml): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET without LIBXML_NOENT: no network, no external entity substitution
            $parsed = simplexml_load_string($xml, \SimpleXMLElement::class, \LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $parsed instanceof \SimpleXMLElement ? $parsed : null;
    }
}
