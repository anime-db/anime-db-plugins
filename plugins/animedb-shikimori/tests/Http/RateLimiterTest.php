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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Http;

use AnimeDb\Plugins\AnimedbShikimori\Http\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    /**
     * A fake clock that only advances when the fake sleeper "sleeps" it, so the test drives
     * time deterministically instead of depending on wall-clock speed.
     *
     * @return array{0: RateLimiter, 1: callable(): float, 2: callable(): int}
     */
    private static function withFakeTime(int $maxPerSecond = 5, int $maxPerMinute = 90): array
    {
        $time = 0.0;
        $sleepCalls = 0;

        $clock = static function () use (&$time): float {
            return $time;
        };
        $sleeper = static function (float $seconds) use (&$time, &$sleepCalls): void {
            $time += $seconds;
            ++$sleepCalls;
        };

        $limiter = new RateLimiter($maxPerSecond, $maxPerMinute, $clock, $sleeper);

        return [
            $limiter,
            static function () use (&$time): float {
                return $time;
            },
            static function () use (&$sleepCalls): int {
                return $sleepCalls;
            },
        ];
    }

    public function testFirstBurstUpToBudgetDoesNotSleep(): void
    {
        [$limiter, $elapsed, $sleepCalls] = self::withFakeTime(maxPerSecond: 5, maxPerMinute: 90);

        for ($i = 0; $i < 5; ++$i) {
            $limiter->acquire();
        }

        self::assertSame(0, $sleepCalls());
        self::assertSame(0.0, $elapsed());
    }

    public function testAcquireNeverExceedsFiveRequestsPerSecond(): void
    {
        [$limiter, $elapsed] = self::withFakeTime(maxPerSecond: 5, maxPerMinute: 90);

        $calls = 12;
        for ($i = 0; $i < $calls; ++$i) {
            $limiter->acquire();
        }

        // 5 calls are free (the initial bucket), the remaining 7 must each have been paced to
        // ~1/5s apart — anything less would mean the limiter let a burst through past 5 rps.
        $expectedMinimumElapsed = ($calls - 5) / 5;
        self::assertGreaterThanOrEqual($expectedMinimumElapsed - 0.001, $elapsed());
    }

    public function testAcquireIsAlsoBoundedByThePerMinuteBudget(): void
    {
        // A generous per-second budget isolates the per-minute bucket as the only thing that
        // can force a wait here.
        [$limiter, $elapsed, $sleepCalls] = self::withFakeTime(maxPerSecond: 1000, maxPerMinute: 3);

        for ($i = 0; $i < 4; ++$i) {
            $limiter->acquire();
        }

        self::assertGreaterThan(0, $sleepCalls());
        self::assertGreaterThan(0.0, $elapsed());
    }

    public function testSleepDelegatesToInjectedSleeper(): void
    {
        [$limiter, $elapsed, $sleepCalls] = self::withFakeTime();

        $limiter->sleep(2.5);

        self::assertSame(1, $sleepCalls());
        self::assertSame(2.5, $elapsed());
    }
}
