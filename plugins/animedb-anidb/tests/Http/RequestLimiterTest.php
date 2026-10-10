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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\Http;

use AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface;
use AnimeDb\Plugins\AnimedbAnidb\Dump\FileLock;
use AnimeDb\Plugins\AnimedbAnidb\Http\AniDbRequestException;
use AnimeDb\Plugins\AnimedbAnidb\Http\RequestLimiter;
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
        $this->dir = sys_get_temp_dir().'/anidb-limiter-'.bin2hex(random_bytes(6));
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

    public function testEmptyCacheDirectoryMeansFreeSlot(): void
    {
        $this->limiter()->acquire();

        self::assertSame([], $this->sleeps);
    }

    public function testTwoCallsAreSpacedByTwoSeconds(): void
    {
        $limiter = $this->limiter();
        $limiter->acquire();
        $limiter->acquire();
        $limiter->acquire();

        self::assertEqualsWithDelta(4.0, array_sum($this->sleeps), 0.001);
        self::assertEqualsWithDelta(2.0, $this->sleeps[0] + ($this->sleeps[1] ?? 0.0), 0.001);
    }

    public function testSecondInstanceSharesTheQueueThroughDisk(): void
    {
        $this->limiter()->acquire();
        $this->limiter()->acquire();

        self::assertEqualsWithDelta(2.0, array_sum($this->sleeps), 0.001);
    }

    public function testSlotPassedWhileIdleIsFree(): void
    {
        $limiter = $this->limiter();
        $limiter->acquire();
        $this->now += 10.0;
        $limiter->acquire();

        self::assertSame([], $this->sleeps);
    }

    public function testSleepHappensAfterTheLockIsReleased(): void
    {
        $lockPath = $this->dir.'/anidb-api.lock';
        $lockFree = [];
        $limiter = $this->limiter(function (float $seconds) use ($lockPath, &$lockFree): void {
            $probe = fopen($lockPath, 'c');
            $lockFree[] = $probe !== false && flock($probe, \LOCK_EX | \LOCK_NB);
            if ($probe !== false) {
                fclose($probe);
            }
        });

        $limiter->acquire();
        $limiter->acquire();

        self::assertSame([true, true], $lockFree);
        self::assertFileExists($lockPath);
        self::assertSame($lockPath, $limiter->lockPath());
    }

    public function testSlotFurtherThanTwentySecondsIsNotReserved(): void
    {
        $this->sleepAdvancesClock = false;
        $limiter = $this->limiter();
        // slots now+0 .. now+20 are reservable: eleven calls
        for ($i = 0; $i < 11; ++$i) {
            $limiter->acquire();
        }
        $slotBefore = (string) file_get_contents($this->dir.'/anidb-api.slot');
        $sleepsBefore = $this->sleeps;

        try {
            $limiter->acquire();
            self::fail('Expected AniDbRequestException.');
        } catch (AniDbRequestException) {
        }

        self::assertSame($slotBefore, (string) file_get_contents($this->dir.'/anidb-api.slot'));
        self::assertSame($sleepsBefore, $this->sleeps);
    }

    public function testHeartbeatIsCalledWhileSleeping(): void
    {
        $limiter = $this->limiter();
        $limiter->acquire();

        $beats = 0;
        $limiter->acquire(static function () use (&$beats): void {
            ++$beats;
        });

        self::assertSame(2, $beats);
    }

    public function testNoHeartbeatWithoutWaiting(): void
    {
        $beats = 0;
        $this->limiter()->acquire(static function () use (&$beats): void {
            ++$beats;
        });

        self::assertSame(0, $beats);
    }

    public function testRequestsFromParallelProcessesAreSpaced(): void
    {
        $script = $this->dir.'/worker.php';
        file_put_contents($script, <<<'PHP'
            <?php
            require $argv[1];
            foreach (['Dump/FileLock', 'Http/AniDbRequestException', 'Http/RequestLimiter'] as $file) {
                require $argv[3].'/'.$file.'.php';
            }
            $cache = new class($argv[2]) implements AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface {
                public function __construct(private string $dir) {}
                public function path(): string { return $this->dir; }
            };
            $limiter = new AnimeDb\Plugins\AnimedbAnidb\Http\RequestLimiter(
                $cache,
                new AnimeDb\Plugins\AnimedbAnidb\Dump\FileLock(),
                null,
                static function (float $s): void {},
            );
            $limiter->acquire();
            $limiter->acquire();
            PHP);

        $autoload = \dirname(__DIR__, 4).'/vendor/autoload.php';

        $start = microtime(true);
        $processes = [];
        $pipes = [];
        for ($i = 0; $i < 5; ++$i) {
            $processes[] = proc_open(
                [\PHP_BINARY, $script, $autoload, $this->dir, \dirname(__DIR__, 2).'/src'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
            );
        }
        foreach ($processes as $i => $process) {
            $stderr = (string) stream_get_contents($pipes[$i][2]);
            stream_get_contents($pipes[$i][1]);
            self::assertSame(0, proc_close($process), 'Worker failed: '.$stderr);
        }
        $end = microtime(true);

        // five workers reserve twice each: ten reservations, none lost, so the next free slot
        // moved by ten intervals; one lost update would put it below the lower bound
        $next = (float) file_get_contents($this->dir.'/anidb-api.slot');
        self::assertGreaterThanOrEqual($start + 20.0 - 0.001, $next);
        self::assertLessThanOrEqual($end + 20.0 + 0.001, $next);
    }

    public function testSlotFromTheFutureIsDropped(): void
    {
        file_put_contents($this->dir.'/anidb-api.slot', \sprintf('%.6F', $this->now + 3600));

        $this->limiter()->acquire();

        self::assertSame([], $this->sleeps);
        self::assertEqualsWithDelta($this->now + 2.0, (float) file_get_contents($this->dir.'/anidb-api.slot'), 0.001);
    }

    public function testNonFiniteSlotIsIgnored(): void
    {
        file_put_contents($this->dir.'/anidb-api.slot', '1e400');

        $this->limiter()->acquire();

        self::assertSame([], $this->sleeps);
    }

    public function testUnavailableCacheDirectoryIsARequestException(): void
    {
        $cache = $this->createMock(PluginCacheDirectoryInterface::class);
        $cache->method('path')->willReturn($this->dir.'/missing');
        $limiter = new RequestLimiter($cache, new FileLock(), fn (): float => $this->now, static function (float $s): void {});

        $this->expectException(AniDbRequestException::class);
        $limiter->acquire();
    }

    private function limiter(?\Closure $sleeper = null): RequestLimiter
    {
        $cache = $this->createMock(PluginCacheDirectoryInterface::class);
        $cache->method('path')->willReturn($this->dir);

        return new RequestLimiter(
            $cache,
            new FileLock(),
            fn (): float => $this->now,
            function (float $seconds) use ($sleeper): void {
                $this->sleeps[] = $seconds;
                if ($sleeper !== null) {
                    $sleeper($seconds);
                }
                if ($this->sleepAdvancesClock) {
                    $this->now += $seconds;
                }
            },
        );
    }
}
