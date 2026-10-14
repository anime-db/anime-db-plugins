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

namespace AnimeDb\Plugins\AnimedbWorldart\Http;

use AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface;

/**
 * Disk cache of card pages by id: the raw bytes of the response in `windows-1251`, valid for
 * 24 hours. Only pages recognised as a card are put here; parsing happens on every read, so a
 * change of the parser does not need a cache reset. The synopsis page of an entry is kept the same
 * way, in a separate file per id.
 */
final class CardCache
{
    public const TTL = 86400;

    /**
     * @param \Closure(): int|null $clock
     */
    public function __construct(
        private readonly PluginCacheDirectoryInterface $cacheDirectory,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function get(int $id): ?string
    {
        return $this->read($this->path(...), $id);
    }

    public function put(int $id, string $bytes): void
    {
        $this->write($this->path(...), $id, $bytes);
    }

    public function getSynopsis(int $id): ?string
    {
        return $this->read($this->synopsisPath(...), $id);
    }

    public function putSynopsis(int $id, string $bytes): void
    {
        $this->write($this->synopsisPath(...), $id, $bytes);
    }

    /**
     * @param \Closure(int): string $pathOf
     */
    private function read(\Closure $pathOf, int $id): ?string
    {
        try {
            $path = $pathOf($id);
        } catch (\RuntimeException) {
            return null;
        }
        clearstatcache(true, $path);
        $modified = @filemtime($path);
        if ($modified === false || $this->now() - $modified >= self::TTL) {
            return null;
        }

        $bytes = @file_get_contents($path);

        return \is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /**
     * @param \Closure(int): string $pathOf
     */
    private function write(\Closure $pathOf, int $id, string $bytes): void
    {
        try {
            $tmp = @tempnam($this->cacheDirectory->path(), 'tmp');
            if ($tmp === false) {
                return;
            }
            if (@file_put_contents($tmp, $bytes) === false || !@rename($tmp, $pathOf($id))) {
                @unlink($tmp);
            }
        } catch (\RuntimeException) {
            // an unavailable cache directory only costs the cache, not the downloaded card
        }
    }

    public function path(int $id): string
    {
        return \sprintf('%s/worldart-card-%d.html', $this->cacheDirectory->path(), $id);
    }

    public function synopsisPath(int $id): string
    {
        return \sprintf('%s/worldart-synopsis-%d.html', $this->cacheDirectory->path(), $id);
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
