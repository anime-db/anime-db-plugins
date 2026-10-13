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

namespace AnimeDb\Plugins\AnimedbWorldart\Tests\Support;

use AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface;
use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\Plugins\AnimedbWorldart\Http\FileLock;
use AnimeDb\Plugins\AnimedbWorldart\Http\RequestLimiter;
use AnimeDb\Plugins\AnimedbWorldart\Http\WorldartClient;
use AnimeDb\Plugins\AnimedbWorldart\WorldartFiller;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Wires the plugin on a temporary cache directory, a scripted HTTP client and a movable clock.
 * Nothing here touches the network.
 */
abstract class WorldartTestCase extends TestCase
{
    protected string $cacheDir;
    protected float $now = 1_800_000_000.0;

    /** @var list<string> */
    protected array $requests = [];

    /** @var list<array<string, string>> */
    protected array $requestHeaders = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    protected array $responses = [];

    protected WorldartClient $client;
    protected WorldartFiller $filler;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/worldart-test-'.bin2hex(random_bytes(6));
        mkdir($this->cacheDir);
        $this->requests = [];
        $this->requestHeaders = [];
        $this->responses = [];

        $cache = $this->createMock(PluginCacheDirectoryInterface::class);
        $cache->method('path')->willReturn($this->cacheDir);
        $manifest = $this->createMock(OwnManifestInterface::class);
        $manifest->method('id')->willReturn('testvendor-probe');
        $manifest->method('version')->willReturn('9.8.7');

        $this->client = new WorldartClient(
            $this->httpClient(),
            $this->requestFactory(),
            $manifest,
            new RequestLimiter(
                $cache,
                new FileLock(),
                fn (): float => $this->now,
                function (float $seconds): void {
                    $this->now += $seconds;
                },
            ),
        );
        $this->filler = new WorldartFiller($this->client, $manifest);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
    }

    protected function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixture/'.$name);
    }

    protected function response(int $status, string $body = ''): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    protected function queueFixture(string $name): void
    {
        $this->responses[] = $this->response(200, $this->fixture($name));
    }

    private function httpClient(): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request): ResponseInterface {
            $next = array_shift($this->responses);
            if ($next === null) {
                self::fail('Unexpected HTTP request.');
            }
            if ($next instanceof ClientExceptionInterface) {
                throw $next;
            }

            return $next;
        });

        return $client;
    }

    private function requestFactory(): RequestFactoryInterface
    {
        $factory = $this->createMock(RequestFactoryInterface::class);
        $factory->method('createRequest')->willReturnCallback(function (string $method, $uri): RequestInterface {
            self::assertSame('GET', $method);
            $this->requests[] = (string) $uri;
            $index = \count($this->requests) - 1;
            $request = $this->createMock(RequestInterface::class);
            $request->method('withHeader')->willReturnCallback(
                function (string $name, $value) use ($request, $index): RequestInterface {
                    $this->requestHeaders[$index][$name] = (string) $value;

                    return $request;
                },
            );

            return $request;
        });

        return $factory;
    }
}
