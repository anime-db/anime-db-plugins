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

use AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface;

/**
 * Disk cache of the `hotanime` list: the decompressed XML in a single file, valid for 24 hours.
 * AniDB asks not to request the same data more than once a day.
 */
final class HotAnimeCache
{
    public const TTL = 86400;
    /** Pause after a failed request, so a persistent failure is not retried on every render. */
    public const FAILURE_BACKOFF = 3600;
    private const FILE = 'anidb-hotanime.xml';
    private const FAILURE_FILE = 'anidb-hotanime.failed';

    /**
     * @param \Closure(): int|null $clock
     */
    public function __construct(
        private readonly PluginCacheDirectoryInterface $cacheDirectory,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function get(): ?string
    {
        try {
            $path = $this->path();
        } catch (\RuntimeException) {
            return null;
        }
        clearstatcache(true, $path);
        $modified = @filemtime($path);
        if ($modified === false || $this->now() - $modified >= self::TTL) {
            return null;
        }

        $xml = @file_get_contents($path);

        return \is_string($xml) && $xml !== '' ? $xml : null;
    }

    /** The cached list regardless of its age, for use while a failed request is backing off. */
    public function getStale(): ?string
    {
        try {
            $xml = @file_get_contents($this->path());
        } catch (\RuntimeException) {
            return null;
        }

        return \is_string($xml) && $xml !== '' ? $xml : null;
    }

    public function markFailed(): void
    {
        try {
            @file_put_contents($this->failurePath(), (string) $this->now());
        } catch (\RuntimeException) {
            // without a cache directory there is nowhere to remember the failure
        }
    }

    public function isBackingOff(): bool
    {
        try {
            $at = @file_get_contents($this->failurePath());
        } catch (\RuntimeException) {
            return false;
        }

        return \is_string($at) && ctype_digit($at) && $this->now() - (int) $at < self::FAILURE_BACKOFF;
    }

    public function put(string $xml): void
    {
        try {
            $tmp = @tempnam($this->cacheDirectory->path(), 'tmp');
            if ($tmp === false) {
                return;
            }
            if (@file_put_contents($tmp, $xml) === false || !@rename($tmp, $this->path())) {
                @unlink($tmp);
            }
        } catch (\RuntimeException) {
            // an unavailable cache directory only costs the cache, not the downloaded list
        }
    }

    public function path(): string
    {
        return $this->cacheDirectory->path().'/'.self::FILE;
    }

    private function failurePath(): string
    {
        return $this->cacheDirectory->path().'/'.self::FAILURE_FILE;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
