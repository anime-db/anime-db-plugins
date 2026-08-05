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

/**
 * Client-side token-bucket rate limiter for the Shikimori GraphQL API.
 *
 * Shikimori enforces 5 requests/second and 90 requests/minute; the host application does not
 * throttle plugin HTTP calls, so a plugin issuing bursts risks an IP ban. {@see self::acquire()}
 * blocks (via the injected sleeper) until both budgets have room, rather than ever throwing —
 * pacing is a normal, expected outcome, not an error.
 *
 * $clock/$sleeper are constructor-injectable (not host-provided types, so this stays safely
 * autowirable — see the class doc of {@see GraphQlClient})
 * purely so tests can drive time deterministically without real sleeps.
 */
final class RateLimiter
{
    private const DEFAULT_MAX_PER_SECOND = 5;
    private const DEFAULT_MAX_PER_MINUTE = 90;
    private const MICROSECONDS_PER_SECOND = 1_000_000;

    private float $secondBucketTokens;
    private float $lastSecondRefill;
    private float $minuteBucketTokens;
    private float $lastMinuteRefill;

    /** @var callable(): float */
    private $clock;

    /** @var callable(float): void */
    private $sleeper;

    public function __construct(
        private readonly int $maxPerSecond = self::DEFAULT_MAX_PER_SECOND,
        private readonly int $maxPerMinute = self::DEFAULT_MAX_PER_MINUTE,
        ?callable $clock = null,
        ?callable $sleeper = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * self::MICROSECONDS_PER_SECOND));
            }
        };

        $now = ($this->clock)();
        $this->secondBucketTokens = (float) $this->maxPerSecond;
        $this->lastSecondRefill = $now;
        $this->minuteBucketTokens = (float) $this->maxPerMinute;
        $this->lastMinuteRefill = $now;
    }

    /**
     * Blocks until a request may proceed, then consumes one token from both budgets.
     */
    public function acquire(): void
    {
        $this->refill();

        $waitSeconds = max(
            $this->secondBucketTokens < 1 ? (1 - $this->secondBucketTokens) / $this->maxPerSecond : 0.0,
            $this->minuteBucketTokens < 1 ? (1 - $this->minuteBucketTokens) / $this->maxPerMinute * 60 : 0.0,
        );

        if ($waitSeconds > 0) {
            $this->sleep($waitSeconds);
            $this->refill();
        }

        --$this->secondBucketTokens;
        --$this->minuteBucketTokens;
    }

    /**
     * Sleeps for the given duration through the injected sleeper — used by {@see self::acquire()}
     * as well as by the caller to honour a `Retry-After` backoff on HTTP 429 with the same
     * (test-controllable) time source.
     */
    public function sleep(float $seconds): void
    {
        ($this->sleeper)($seconds);
    }

    private function refill(): void
    {
        $now = ($this->clock)();

        $secondsElapsed = $now - $this->lastSecondRefill;
        $this->secondBucketTokens = min(
            (float) $this->maxPerSecond,
            $this->secondBucketTokens + $secondsElapsed * $this->maxPerSecond,
        );
        $this->lastSecondRefill = $now;

        $minutesElapsed = ($now - $this->lastMinuteRefill) / 60;
        $this->minuteBucketTokens = min(
            (float) $this->maxPerMinute,
            $this->minuteBucketTokens + $minutesElapsed * $this->maxPerMinute,
        );
        $this->lastMinuteRefill = $now;
    }
}
