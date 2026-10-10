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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\Dump;

use AnimeDb\Plugins\AnimedbAnidb\Dump\DumpDownloader;
use AnimeDb\Plugins\AnimedbAnidb\Tests\Support\AnidbTestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class DumpDownloaderTest extends AnidbTestCase
{
    public function testEmptyCacheAndNoAttemptDownloadsUnconditionally(): void
    {
        $this->responses = [$this->dumpResponse($this->fixtureDump(), ['ETag' => '"v1"', 'Last-Modified' => 'Sat, 10 Oct 2026 03:00:00 GMT'])];

        $this->downloader->refresh();

        self::assertCount(1, $this->requests);
        self::assertSame(DumpDownloader::DUMP_URL, $this->requests[0]['url']);
        self::assertSame(['User-Agent' => 'AnimeDB animedb-anidb/0.1.0 (+https://anime-db.org/)'], $this->requests[0]['headers']);
        self::assertSame($this->fixtureDump(), file_get_contents($this->files->dumpPath()));
        self::assertSame(
            ['etag' => '"v1"', 'last_modified' => 'Sat, 10 Oct 2026 03:00:00 GMT'],
            $this->files->readMeta(),
        );
        self::assertSame(['dump_attempt' => ['at' => $this->now, 'kind' => 'response']], $this->settings->data);
        self::assertSame([], glob($this->cacheDir.'/tmp*'));
    }

    public function testFreshAttemptSendsNoRequestEvenWithEmptyCache(): void
    {
        $this->settings->data['dump_attempt'] = ['at' => $this->now - 86399, 'kind' => 'response'];

        $this->downloader->refresh();

        self::assertSame([], $this->requests);
        self::assertFileDoesNotExist($this->files->dumpPath());
    }

    public function testResponseAttemptExpiresAfterADay(): void
    {
        $this->settings->data['dump_attempt'] = ['at' => $this->now - 86400, 'kind' => 'response'];
        $this->responses = [$this->response(500)];

        $this->downloader->refresh();

        self::assertCount(1, $this->requests);
    }

    public function testNotModifiedRecordsAttemptAndKeepsDump(): void
    {
        $this->seedDump('1|1|x-jat|Old'."\n");
        $this->now += 86400;
        $this->responses = [$this->response(304)];

        $this->downloader->refresh();

        self::assertSame('1|1|x-jat|Old'."\n", file_get_contents($this->files->dumpPath()));
        self::assertSame(['at' => $this->now, 'kind' => 'response'], $this->settings->data['dump_attempt']);
        self::assertSame('"v1"', $this->files->readMeta()['etag']);
    }

    public function testErrorResponseRecordsAttempt(): void
    {
        $this->responses = [$this->response(503)];

        $this->downloader->refresh();

        self::assertSame(['at' => $this->now, 'kind' => 'response'], $this->settings->data['dump_attempt']);
        self::assertFileDoesNotExist($this->files->dumpPath());
    }

    public function testBrokenBodyStillRecordsAttemptAndKeepsOldDump(): void
    {
        $this->seedDump('1|1|x-jat|Old'."\n");
        $this->now += 86400;
        $this->responses = [$this->response(200, 'this is not gzip', ['ETag' => '"v2"'])];

        $this->downloader->refresh();

        self::assertSame(['at' => $this->now, 'kind' => 'response'], $this->settings->data['dump_attempt']);
        self::assertSame('1|1|x-jat|Old'."\n", file_get_contents($this->files->dumpPath()));
        self::assertSame('"v1"', $this->files->readMeta()['etag']);
    }

    public function testTransportFailureRetriesAfterAnHourNotADay(): void
    {
        $this->responses = [$this->createMock(ClientExceptionInterface::class)];
        $this->downloader->refresh();
        self::assertSame(['at' => $this->now, 'kind' => 'transport_failure'], $this->settings->data['dump_attempt']);

        $this->now += 3599;
        $this->downloader->refresh();
        self::assertCount(1, $this->requests);

        $this->now += 1;
        $this->responses = [$this->dumpResponse($this->fixtureDump())];
        $this->downloader->refresh();
        self::assertCount(2, $this->requests);
        self::assertFileExists($this->files->dumpPath());
    }

    public function testConditionalHeadersSentOnlyWithDumpFile(): void
    {
        $this->seedDump('1|1|x-jat|Old'."\n");
        $this->files->writeMeta('"v1"', 'Sat, 10 Oct 2026 03:00:00 GMT');
        $this->now += 86400;
        $this->responses = [$this->response(304)];

        $this->downloader->refresh();

        self::assertSame('"v1"', $this->requests[0]['headers']['If-None-Match']);
        self::assertSame('Sat, 10 Oct 2026 03:00:00 GMT', $this->requests[0]['headers']['If-Modified-Since']);
    }

    public function testNoConditionalHeadersWithoutDumpFileEvenIfMetaExists(): void
    {
        $this->files->writeMeta('"v1"', 'Sat, 10 Oct 2026 03:00:00 GMT');
        $this->responses = [$this->response(500)];

        $this->downloader->refresh();

        self::assertArrayNotHasKey('If-None-Match', $this->requests[0]['headers']);
        self::assertArrayNotHasKey('If-Modified-Since', $this->requests[0]['headers']);
    }

    public function testValidatorsLiveInTheCacheMetaFileNotInSettings(): void
    {
        $this->responses = [$this->dumpResponse($this->fixtureDump(), ['ETag' => '"secret-etag"'])];

        $this->downloader->refresh();

        self::assertStringContainsString('secret-etag', (string) file_get_contents($this->files->metaPath()));
        self::assertStringNotContainsString('secret-etag', json_encode($this->settings->data, \JSON_THROW_ON_ERROR));
        self::assertSame(['dump_attempt'], array_keys($this->settings->data));
    }
}
