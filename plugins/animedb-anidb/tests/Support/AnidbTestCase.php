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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\Support;

use AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface;
use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\Plugins\AnimedbAnidb\AnidbFiller;
use AnimeDb\Plugins\AnimedbAnidb\Dump\AttemptStore;
use AnimeDb\Plugins\AnimedbAnidb\Dump\DumpDownloader;
use AnimeDb\Plugins\AnimedbAnidb\Dump\DumpFiles;
use AnimeDb\Plugins\AnimedbAnidb\Dump\FileLock;
use AnimeDb\Plugins\AnimedbAnidb\Index\IndexBuilder;
use AnimeDb\Plugins\AnimedbAnidb\Index\IndexReader;
use AnimeDb\Plugins\AnimedbAnidb\TitleSearch;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Wires the whole plugin on a temporary cache directory, an in-memory settings store, a
 * scripted HTTP client and a movable clock. Nothing here touches the network.
 */
abstract class AnidbTestCase extends TestCase
{
    protected string $cacheDir;
    protected ArraySettingsStore $settings;
    protected int $now = 1_800_000_000;

    /** @var list<array{url: string, headers: array<string, string>}> */
    protected array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    protected array $responses = [];

    /** @var array<int, \stdClass> */
    private array $records = [];

    protected DumpFiles $files;
    protected DumpDownloader $downloader;
    protected AnidbFiller $filler;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/anidb-test-'.bin2hex(random_bytes(6));
        mkdir($this->cacheDir);
        $this->settings = new ArraySettingsStore();
        $this->requests = [];
        $this->responses = [];

        $cache = $this->createMock(PluginCacheDirectoryInterface::class);
        $cache->method('path')->willReturn($this->cacheDir);
        $manifest = $this->createMock(OwnManifestInterface::class);
        $manifest->method('id')->willReturn('testvendor-probe');
        $manifest->method('version')->willReturn('9.8.7');

        $this->files = new DumpFiles($cache);
        $this->downloader = new DumpDownloader(
            $this->httpClient(),
            $this->requestFactory(),
            $manifest,
            new AttemptStore($this->settings, $this->files),
            $this->files,
            fn (): int => $this->now,
        );
        $reader = new IndexReader($this->files);
        $this->filler = new AnidbFiller(
            new TitleSearch($this->downloader, new IndexBuilder($this->files), $reader, $this->files, new FileLock()),
            $manifest,
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
    }

    protected function fixtureDump(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixture/anime-titles.dat');
    }

    /**
     * @param array<string, string> $headers
     */
    protected function response(int $status, string $body = '', array $headers = []): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => $headers[$name] ?? '',
        );

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function dumpResponse(string $dump, array $headers = ['ETag' => '"v1"']): ResponseInterface
    {
        return $this->response(200, (string) gzencode($dump), $headers);
    }

    /** Puts a ready dump and a fresh "response received" attempt in place, as after a download. */
    protected function seedDump(string $dump, string $etag = '"v1"'): void
    {
        file_put_contents($this->files->dumpPath(), $dump);
        $this->files->writeMeta($etag, null);
        $this->settings->data['dump_attempt'] = ['at' => $this->now, 'kind' => 'response'];
    }

    protected function httpClient(): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request): ResponseInterface {
            $record = $this->records[spl_object_id($request)];
            $this->requests[] = ['url' => $record->url, 'headers' => $record->headers];
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

    protected function requestFactory(): RequestFactoryInterface
    {
        $factory = $this->createMock(RequestFactoryInterface::class);
        $factory->method('createRequest')->willReturnCallback(function (string $method, $uri): RequestInterface {
            $record = new \stdClass();
            $record->url = (string) $uri;
            $record->headers = [];
            $request = $this->createMock(RequestInterface::class);
            $request->method('withHeader')->willReturnCallback(
                function (string $name, $value) use ($record, $request): RequestInterface {
                    $record->headers[$name] = (string) $value;

                    return $request;
                },
            );
            $this->records[spl_object_id($request)] = $record;

            return $request;
        });

        return $factory;
    }
}
