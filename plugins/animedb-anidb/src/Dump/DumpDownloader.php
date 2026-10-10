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
 * handling it still counts. The body is untrusted: its size and the unpacked size are capped, and a body
 * that does not unpack to something shaped like the title dump leaves the existing dump alone. The record is strict: it applies even when the dump file is
 * missing. The request is conditional (`If-None-Match`/`If-Modified-Since` from the meta file)
 * only when the dump file exists. Must be called under the lock.
 */
final class DumpDownloader
{
    public const DUMP_URL = 'https://anidb.net/api/anime-titles.dat.gz';

    /** The real dump is a few MB unpacked; anything beyond these limits is not a title dump. */
    public const MAX_BODY_BYTES = 16 * 1024 * 1024;
    public const MAX_DUMP_BYTES = 64 * 1024 * 1024;
    private const MIN_DUMP_LINES = 10;

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
            $stream = $response->getBody();
            $size = $stream->getSize();
            if ($size !== null && $size > self::MAX_BODY_BYTES) {
                return;
            }
            $body = (string) $stream;
        } catch (\RuntimeException) {
            return;
        }

        if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
            return;
        }

        $dump = @gzdecode($body, self::MAX_DUMP_BYTES);
        if ($dump === false || strlen($dump) > self::MAX_DUMP_BYTES || !self::looksLikeDump($dump)) {
            return;
        }

        $etag = $response->getHeaderLine('ETag');
        $lastModified = $response->getHeaderLine('Last-Modified');

        // Drop the old validators first: if the process stops between the two renames, the
        // version falls back to file size and mtime and the new dump is still re-indexed.
        @unlink($this->files->metaPath());
        $this->files->writeAtomic($this->files->dumpPath(), $dump);
        $this->files->writeMeta($etag !== '' ? $etag : null, $lastModified !== '' ? $lastModified : null);
    }

    private static function looksLikeDump(string $dump): bool
    {
        // the head is enough: a dump has thousands of title lines right after its comment header
        $head = substr($dump, 0, 65536);

        return preg_match_all('/^\d+\|[1-4]\|[^|\r\n]*\|[^\r\n]+$/m', $head) >= self::MIN_DUMP_LINES;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
