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

namespace AnimeDb\Plugins\AnimedbAnidb\Dump;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\Plugins\AnimedbAnidb\Http\UserAgent;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Downloads the daily title dump, at most once per 24 hours (1 hour after a transport failure).
 *
 * The attempt is recorded the moment an HTTP response arrives — any status, 304 and errors
 * included — before the body is decompressed or parsed, so a broken body or a crash while
 * handling it still counts. The record is strict: it applies even when the dump file is
 * missing. The request is conditional (`If-None-Match`/`If-Modified-Since` from the meta file)
 * only when the dump file exists. Must be called under the lock.
 */
final class DumpDownloader
{
    public const DUMP_URL = 'https://anidb.net/api/anime-titles.dat.gz';

    /**
     * @param \Closure(): int|null $clock
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly OwnManifestInterface $ownManifest,
        private readonly AttemptStore $attempts,
        private readonly DumpFiles $files,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function isDue(): bool
    {
        return $this->attempts->isDue($this->now());
    }

    public function refresh(): void
    {
        if (!$this->isDue()) {
            return;
        }

        $request = $this->requestFactory->createRequest('GET', self::DUMP_URL)
            ->withHeader('User-Agent', UserAgent::forManifest($this->ownManifest));

        if ($this->files->hasDump()) {
            $meta = $this->files->readMeta();
            if ($meta['etag'] !== null) {
                $request = $request->withHeader('If-None-Match', $meta['etag']);
            }
            if ($meta['last_modified'] !== null) {
                $request = $request->withHeader('If-Modified-Since', $meta['last_modified']);
            }
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface) {
            $this->attempts->record(AttemptStore::KIND_TRANSPORT_FAILURE, $this->now());

            return;
        }

        $this->attempts->record(AttemptStore::KIND_RESPONSE, $this->now());

        if ($response->getStatusCode() !== 200) {
            return;
        }

        try {
            $body = (string) $response->getBody();
        } catch (\RuntimeException) {
            return;
        }

        $dump = $body === '' ? false : @gzdecode($body);
        if ($dump === false || $dump === '') {
            return;
        }

        $etag = $response->getHeaderLine('ETag');
        $lastModified = $response->getHeaderLine('Last-Modified');

        $this->files->writeAtomic($this->files->dumpPath(), $dump);
        $this->files->writeMeta($etag !== '' ? $etag : null, $lastModified !== '' ? $lastModified : null);
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
