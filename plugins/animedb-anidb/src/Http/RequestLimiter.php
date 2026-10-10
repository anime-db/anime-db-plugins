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
use AnimeDb\Plugins\AnimedbAnidb\Dump\FileLock;

/**
 * Cross-process limiter: at most one API request per {@see self::INTERVAL} seconds for the
 * whole plugin, shared by every process through files in the cache directory.
 *
 * A caller reserves a slot in the common queue under an exclusive lock on a dedicated lock
 * file (never replaced through `rename()`): the time of the next free slot is read, that slot is
 * taken and the following one is moved {@see self::INTERVAL} seconds later. The lock is released
 * before the caller sleeps until its slot, so a waiting process never holds the others. A slot
 * further than {@see self::MAX_WAIT} seconds ahead is not reserved at all. A missing slot file
 * means the slot is free now. One instance serves the whole plugin; one rule applies to every call.
 */
final class RequestLimiter
{
    public const INTERVAL = 2.0;
    public const MAX_WAIT = 20.0;

    private const HEARTBEAT_STEP = 1.0;

    /**
     * @param \Closure(): float|null     $clock  seconds since the epoch, with a fraction
     * @param \Closure(float): void|null $sleeper
     */
    public function __construct(
        private readonly PluginCacheDirectoryInterface $cacheDirectory,
        private readonly FileLock $lock = new FileLock(),
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $sleeper = null,
    ) {
    }

    /**
     * Blocks until the caller's slot comes. `$onHeartbeat` is called while sleeping.
     *
     * @param callable(): void|null $onHeartbeat
     *
     * @throws AniDbRequestException when the queue is longer than {@see self::MAX_WAIT}
     */
    public function acquire(?callable $onHeartbeat = null): void
    {
        $wait = $this->lock->withLock($this->lockPath(), function (): float {
            $now = $this->now();
            $slot = max($now, $this->readNextSlot());
            if ($slot - $now > self::MAX_WAIT) {
                throw new AniDbRequestException('The AniDB request queue is too long, try again later.');
            }
            $this->writeNextSlot($slot + self::INTERVAL);

            return $slot - $now;
        });

        // the lock is released here: sleeping never blocks other processes
        while ($wait > 0.0) {
            if ($onHeartbeat !== null) {
                $onHeartbeat();
            }
            $step = min($wait, self::HEARTBEAT_STEP);
            $this->sleep($step);
            $wait -= $step;
        }
    }

    public function lockPath(): string
    {
        return $this->cacheDirectory->path().'/anidb-api.lock';
    }

    private function slotPath(): string
    {
        return $this->cacheDirectory->path().'/anidb-api.slot';
    }

    private function readNextSlot(): float
    {
        $raw = @file_get_contents($this->slotPath());

        return \is_string($raw) && is_numeric($raw) ? (float) $raw : 0.0;
    }

    private function writeNextSlot(float $slot): void
    {
        // read and written only under the lock; the lock file, not this one, carries the lock
        if (file_put_contents($this->slotPath(), \sprintf('%.6F', $slot)) === false) {
            throw new AniDbRequestException('Cannot reserve an AniDB request slot.');
        }
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    private function sleep(float $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        usleep((int) ceil($seconds * 1_000_000));
    }
}
