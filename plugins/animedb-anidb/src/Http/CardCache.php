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
 * Disk cache of anime cards by AniDB id: the decompressed XML, valid for 24 hours. AniDB asks
 * not to request the same data more than once a day.
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

    public function get(int $aid): ?string
    {
        $path = $this->path($aid);
        clearstatcache(true, $path);
        $modified = @filemtime($path);
        if ($modified === false || $this->now() - $modified >= self::TTL) {
            return null;
        }

        $xml = @file_get_contents($path);

        return \is_string($xml) && $xml !== '' ? $xml : null;
    }

    public function put(int $aid, string $xml): void
    {
        $directory = $this->cacheDirectory->path();
        $tmp = @tempnam($directory, 'tmp');
        if ($tmp === false) {
            return;
        }
        if (@file_put_contents($tmp, $xml) === false || !@rename($tmp, $this->path($aid))) {
            @unlink($tmp);
        }
    }

    public function path(int $aid): string
    {
        return \sprintf('%s/anidb-card-%d.xml', $this->cacheDirectory->path(), $aid);
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
