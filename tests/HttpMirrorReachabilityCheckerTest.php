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

namespace AnimeDb\Plugins\Tools\Tests;

use AnimeDb\Plugins\Tools\HttpMirrorReachabilityChecker;
use PHPUnit\Framework\TestCase;

/**
 * Exercises {@see HttpMirrorReachabilityChecker} against a real HTTP server (PHP's built-in
 * server + a tiny fixture router) instead of a fake, since its whole job is to interpret real HTTP
 * status codes and the 405-to-ranged-GET fallback correctly.
 */
final class HttpMirrorReachabilityCheckerTest extends TestCase
{
    private static ?string $baseUrl = null;

    /** @var resource|null */
    private static $serverProcess;

    public static function setUpBeforeClass(): void
    {
        $router = \dirname(__DIR__).'/tests/fixtures/http-mirror-reachability-router.php';
        $port = 8000 + random_int(1, 900);
        self::$baseUrl = 'http://127.0.0.1:'.$port;

        self::$serverProcess = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:'.$port, $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertServerIsUp(self::$baseUrl.'/ok');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
    }

    private static function assertServerIsUp(string $url): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $connection = @fsockopen('127.0.0.1', (int) parse_url($url, \PHP_URL_PORT), $errno, $errstr, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50000);
        }

        self::fail('Built-in PHP server did not start in time.');
    }

    public function testReachableUrlReturnsTrue(): void
    {
        $checker = new HttpMirrorReachabilityChecker(maxAttempts: 1);

        self::assertTrue($checker->isReachable(self::$baseUrl.'/ok'));
    }

    public function testNotFoundUrlReturnsFalseAfterExhaustingRetries(): void
    {
        $checker = new HttpMirrorReachabilityChecker(maxAttempts: 2, backoffMilliseconds: 1);

        self::assertFalse($checker->isReachable(self::$baseUrl.'/notfound'));
    }

    public function testHeadRejectedWith405FallsBackToRangedGet(): void
    {
        $checker = new HttpMirrorReachabilityChecker(maxAttempts: 1);

        self::assertTrue($checker->isReachable(self::$baseUrl.'/head-405'));
    }

    public function testRetriesRecoverFromATransientFailure(): void
    {
        $counterFile = sys_get_temp_dir().'/http-mirror-reachability-flaky-'.bin2hex(random_bytes(8));
        $checker = new HttpMirrorReachabilityChecker(maxAttempts: 3, backoffMilliseconds: 1);

        try {
            self::assertTrue($checker->isReachable(self::$baseUrl.'/flaky?counter='.urlencode($counterFile)));
        } finally {
            if (is_file($counterFile)) {
                unlink($counterFile);
            }
        }
    }

    public function testUnreachableHostReturnsFalse(): void
    {
        $checker = new HttpMirrorReachabilityChecker(maxAttempts: 1, timeoutSeconds: 1);

        self::assertFalse($checker->isReachable('http://127.0.0.1:1/unreachable'));
    }
}
