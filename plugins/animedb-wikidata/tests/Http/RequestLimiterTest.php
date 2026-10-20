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

namespace AnimeDb\Plugins\AnimedbWikidata\Tests\Http;

use AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface;
use AnimeDb\Plugins\AnimedbWikidata\Http\FileLock;
use AnimeDb\Plugins\AnimedbWikidata\Http\RequestLimiter;
use AnimeDb\Plugins\AnimedbWikidata\Http\WikidataRequestException;
use PHPUnit\Framework\TestCase;

final class RequestLimiterTest extends TestCase
{
    private string $dir;
    private float $now = 1_800_000_000.0;
    private bool $sleepAdvancesClock = true;

    /** @var list<float> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/wikidata-limiter-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->sleeps = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testFirstCallIsFree(): void
    {
        $this->limiter()->acquire();

        self::assertSame([], $this->sleeps);
    }

    public function testCallsAreSpacedByOneSecond(): void
    {
        $limiter = $this->limiter();
        $limiter->acquire();
        $limiter->acquire();
        $limiter->acquire();

        self::assertEqualsWithDelta(2.0, array_sum($this->sleeps), 0.001);
    }

    public function testSecondInstanceSharesTheQueueThroughDisk(): void
    {
        $this->limiter()->acquire();
        $this->limiter()->acquire();

        self::assertEqualsWithDelta(1.0, array_sum($this->sleeps), 0.001);
    }

    public function testLockFileLivesInTheCacheDirectoryAndIsFreeWhileSleeping(): void
    {
        $lockPath = $this->dir.'/wikidata.lock';
        $lockFree = [];
        $limiter = $this->limiter(function () use ($lockPath, &$lockFree): void {
            $probe = fopen($lockPath, 'c');
            $lockFree[] = $probe !== false && flock($probe, \LOCK_EX | \LOCK_NB);
            if ($probe !== false) {
                fclose($probe);
            }
        });

        $limiter->acquire();
        $limiter->acquire();

        self::assertSame([true], $lockFree);
        self::assertSame($lockPath, $limiter->lockPath());
    }

    public function testQueueLongerThanTheLimitIsRefused(): void
    {
        $this->sleepAdvancesClock = false;
        $limiter = $this->limiter();
        for ($i = 0; $i <= (int) (RequestLimiter::MAX_WAIT / RequestLimiter::INTERVAL); ++$i) {
            $limiter->acquire();
        }

        $this->expectException(WikidataRequestException::class);
        $limiter->acquire();
    }

    private function limiter(?\Closure $onSleep = null): RequestLimiter
    {
        $cache = $this->createMock(PluginCacheDirectoryInterface::class);
        $cache->method('path')->willReturn($this->dir);

        return new RequestLimiter(
            $cache,
            new FileLock(),
            fn (): float => $this->now,
            function (float $seconds) use ($onSleep): void {
                $this->sleeps[] = $seconds;
                if ($onSleep !== null) {
                    $onSleep();
                }
                if ($this->sleepAdvancesClock) {
                    $this->now += $seconds;
                }
            },
        );
    }
}
