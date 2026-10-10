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

use AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface;

/**
 * Paths and small helpers for the files this plugin keeps in its cache directory: the
 * decompressed dump, its meta file (`ETag`/`Last-Modified` of that very file), the SQLite
 * index and the lock file. Data files are always written through a temporary file followed
 * by `rename()`, so a reader without a lock sees either the old or the new whole file.
 */
final class DumpFiles
{
    public function __construct(
        private readonly PluginCacheDirectoryInterface $cacheDirectory,
    ) {
    }

    public function dumpPath(): string
    {
        return $this->cacheDirectory->path().'/anime-titles.dat';
    }

    public function metaPath(): string
    {
        return $this->cacheDirectory->path().'/anime-titles.meta.json';
    }

    public function indexPath(): string
    {
        return $this->cacheDirectory->path().'/anime-titles.sqlite';
    }

    public function lockPath(): string
    {
        return $this->cacheDirectory->path().'/anime-titles.lock';
    }

    public function directory(): string
    {
        return $this->cacheDirectory->path();
    }

    public function hasDump(): bool
    {
        return is_file($this->dumpPath());
    }

    /**
     * @return array{etag: ?string, last_modified: ?string}
     */
    public function readMeta(): array
    {
        $meta = ['etag' => null, 'last_modified' => null];
        $raw = is_file($this->metaPath()) ? file_get_contents($this->metaPath()) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return $meta;
        }

        foreach (array_keys($meta) as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                $meta[$key] = $data[$key];
            }
        }

        return $meta;
    }

    public function writeMeta(?string $etag, ?string $lastModified): void
    {
        $this->writeAtomic($this->metaPath(), json_encode(
            ['etag' => $etag, 'last_modified' => $lastModified],
            \JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Version of the dump file on disk: `ETag`, else `Last-Modified`, else size and mtime of the
     * file. Null when there is no dump file.
     */
    public function dumpVersion(): ?string
    {
        if (!$this->hasDump()) {
            return null;
        }

        $meta = $this->readMeta();
        if ($meta['etag'] !== null) {
            return 'etag:'.$meta['etag'];
        }
        if ($meta['last_modified'] !== null) {
            return 'lm:'.$meta['last_modified'];
        }

        clearstatcache(true, $this->dumpPath());

        return 'file:'.filesize($this->dumpPath()).'-'.filemtime($this->dumpPath());
    }

    public function writeAtomic(string $path, string $content): void
    {
        $tmp = $this->temporaryPath();
        if (file_put_contents($tmp, $content) === false || !rename($tmp, $path)) {
            @unlink($tmp);

            throw new \RuntimeException('Cannot write a cache file.');
        }
    }

    /**
     * A fresh temporary file in the cache directory (same filesystem, so `rename()` is atomic).
     */
    public function temporaryPath(): string
    {
        $tmp = tempnam($this->directory(), 'tmp');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }

        return $tmp;
    }
}
