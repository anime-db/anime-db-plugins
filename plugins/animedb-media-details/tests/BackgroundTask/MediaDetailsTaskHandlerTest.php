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

namespace AnimeDb\Plugins\AnimedbMediaDetails\Tests\BackgroundTask;

use AnimeDb\Plugins\AnimedbMediaDetails\BackgroundTask\MediaDetailsTaskHandler;
use AnimeDb\Plugins\AnimedbMediaDetails\Tests\Fixture\FakeMediaLibrary;
use AnimeDb\Plugins\AnimedbMediaDetails\Tests\Fixture\FakeMediaProbe;
use AnimeDb\Plugins\AnimedbMediaDetails\Tests\Fixture\FakePluginDataStore;
use AnimeDb\PluginContracts\Background\BackgroundTask;
use AnimeDb\PluginContracts\Media\MediaFile;
use AnimeDb\PluginContracts\Media\MediaInfo;
use AnimeDb\PluginContracts\Model\AnimeId;
use PHPUnit\Framework\TestCase;

final class MediaDetailsTaskHandlerTest extends TestCase
{
    private FakeMediaLibrary $library;
    private FakeMediaProbe $prober;
    private FakePluginDataStore $store;
    private MediaDetailsTaskHandler $handler;

    protected function setUp(): void
    {
        $this->library = new FakeMediaLibrary();
        $this->prober = new FakeMediaProbe();
        $this->store = new FakePluginDataStore();
        $this->handler = new MediaDetailsTaskHandler($this->library, $this->prober, $this->store);
    }

    public function testExitsWithoutWorkOnUnknownTaskName(): void
    {
        $anime = new AnimeId(1);

        $this->handler->handle(new BackgroundTask('some-other-task', $anime));

        self::assertSame(0, $this->library->listFilesCallCount);
        self::assertSame(0, $this->store->writeCallCount);
    }

    public function testExitsWithoutWorkWhenTaskHasNoAnime(): void
    {
        $this->handler->handle(new BackgroundTask('probe-files', null));

        self::assertSame(0, $this->library->listFilesCallCount);
        self::assertSame(0, $this->store->writeCallCount);
    }

    public function testDoesNotProbeFilesThatMatchStoredPayload(): void
    {
        $anime = new AnimeId(2);
        $file = $this->makeFile('01.mkv', 1000, 1_700_000_000);

        $this->store->seed($anime, [
            'generated_at' => '2026-01-01T00:00:00+00:00',
            'files_total' => 1,
            'files' => [
                '01.mkv' => [
                    'handle_size' => 1000,
                    'handle_mtime' => 1_700_000_000,
                    'probe_identity' => 'fake-prober-identity',
                    'size' => 1000,
                    'container' => 'matroska,webm',
                    'duration' => 100.0,
                    'bit_rate' => null,
                    'track_count' => 0,
                    'video' => [],
                    'audio' => [],
                    'subtitles' => [],
                    'other' => [],
                ],
            ],
        ]);
        $this->library->willReturn($anime, [$file]);

        $this->handler->handle(new BackgroundTask('probe-files', $anime));

        self::assertSame(0, $this->prober->probeAllCallCount);
        self::assertSame(0, $this->store->writeCallCount);
    }

    public function testDoesNotEraseStoredPayloadWhenStorageIsUnavailable(): void
    {
        $anime = new AnimeId(3);
        $seeded = ['generated_at' => '2026-01-01T00:00:00+00:00', 'files_total' => 1, 'files' => ['01.mkv' => ['handle_size' => 1]]];
        $this->store->seed($anime, $seeded);
        $this->library->willThrowStorageUnavailable($anime);

        $this->handler->handle(new BackgroundTask('probe-files', $anime));

        self::assertSame(0, $this->store->writeCallCount);
        self::assertSame($seeded, $this->store->read($anime));
    }

    public function testDoesNotEraseStoredPayloadWhenFileListIsEmpty(): void
    {
        $anime = new AnimeId(4);
        $seeded = ['generated_at' => '2026-01-01T00:00:00+00:00', 'files_total' => 1, 'files' => ['01.mkv' => ['handle_size' => 1]]];
        $this->store->seed($anime, $seeded);
        $this->library->willReturn($anime, []);

        $this->handler->handle(new BackgroundTask('probe-files', $anime));

        self::assertSame(0, $this->store->writeCallCount);
        self::assertSame($seeded, $this->store->read($anime));
    }

    public function testRemovesFileMissingFromNonEmptyList(): void
    {
        $anime = new AnimeId(5);
        $remainingFile = $this->makeFile('a.mkv', 1000, 1_700_000_000);

        $this->store->seed($anime, [
            'generated_at' => '2026-01-01T00:00:00+00:00',
            'files_total' => 2,
            'files' => [
                'a.mkv' => [
                    'handle_size' => 1000,
                    'handle_mtime' => 1_700_000_000,
                    'probe_identity' => 'fake-prober-identity',
                    'size' => 1000,
                    'container' => 'matroska,webm',
                    'duration' => null,
                    'bit_rate' => null,
                    'track_count' => 0,
                    'video' => [],
                    'audio' => [],
                    'subtitles' => [],
                    'other' => [],
                ],
                'b.mkv' => [
                    'handle_size' => 500,
                    'handle_mtime' => 1_700_000_000,
                    'probe_identity' => 'fake-prober-identity',
                    'size' => 500,
                    'container' => 'matroska,webm',
                    'duration' => null,
                    'bit_rate' => null,
                    'track_count' => 0,
                    'video' => [],
                    'audio' => [],
                    'subtitles' => [],
                    'other' => [],
                ],
            ],
        ]);
        // "b.mkv" is gone from disk; only "a.mkv" remains, and it still matches the stored payload.
        $this->library->willReturn($anime, [$remainingFile]);

        $this->handler->handle(new BackgroundTask('probe-files', $anime));

        self::assertSame(0, $this->prober->probeAllCallCount);
        self::assertSame(1, $this->store->writeCallCount);
        $stored = $this->store->read($anime);
        self::assertArrayHasKey('files', $stored);
        self::assertArrayHasKey('a.mkv', $stored['files']);
        self::assertArrayNotHasKey('b.mkv', $stored['files']);
        self::assertSame(1, $stored['files_total']);
    }

    public function testWritesProberUnavailableMarkWithoutErasingData(): void
    {
        $anime = new AnimeId(6);
        $seeded = [
            'generated_at' => '2026-01-01T00:00:00+00:00',
            'files_total' => 1,
            'files' => ['01.mkv' => ['handle_size' => 1000]],
        ];
        $this->store->seed($anime, $seeded);
        $this->library->willReturn($anime, [$this->makeFile('02.mkv', 2000, 1_700_000_100)]);
        $this->prober->setUnavailable();

        $this->handler->handle(new BackgroundTask('probe-files', $anime));

        $stored = $this->store->read($anime);
        self::assertArrayHasKey('prober_unavailable_at', $stored);
        self::assertIsString($stored['prober_unavailable_at']);
        self::assertSame($seeded['generated_at'], $stored['generated_at']);
        self::assertSame($seeded['files'], $stored['files']);
    }

    public function testDoesNotCrashWhenWriteThrowsRuntimeException(): void
    {
        $anime = new AnimeId(7);
        $file = $this->makeFile('01.mkv', 1000, 1_700_000_000);
        $info = $this->makeInfo();
        $this->prober->registerResult($file, $info);
        $this->library->willReturn($anime, [$file]);
        $this->store->failOnNextWrite();

        $this->handler->handle(new BackgroundTask('probe-files', $anime));

        self::assertSame(1, $this->store->writeCallCount);
        self::assertSame([], $this->store->read($anime));
    }

    public function testProbesAndStoresNewFile(): void
    {
        $anime = new AnimeId(8);
        $file = $this->makeFile('01.mkv', 1000, 1_700_000_000);
        $info = $this->makeInfo();
        $this->prober->registerResult($file, $info);
        $this->library->willReturn($anime, [$file]);

        $this->handler->handle(new BackgroundTask('probe-files', $anime));

        self::assertSame(1, $this->prober->probeAllCallCount);
        $stored = $this->store->read($anime);
        self::assertSame(1, $stored['files_total']);
        self::assertArrayHasKey('01.mkv', $stored['files']);
        self::assertSame('fake-prober-identity', $stored['files']['01.mkv']['probe_identity']);
        self::assertSame(1, $stored['files']['01.mkv']['track_count']);
    }

    private function makeFile(string $relativePath, int $sizeBytes, int $modifiedAt): MediaFile
    {
        return new MediaFile($relativePath, $relativePath, $sizeBytes, (new \DateTimeImmutable())->setTimestamp($modifiedAt));
    }

    private function makeInfo(): MediaInfo
    {
        return new MediaInfo(
            containerFormat: 'matroska,webm',
            durationSeconds: 120.0,
            sizeBytes: 1000,
            bitRate: 4000,
            trackCount: 1,
            video: [new \AnimeDb\PluginContracts\Media\VideoTrack(0, 'h264', 'High', 1920, 1080, 'yuv420p', 23.976, null)],
            audio: [],
            subtitles: [],
            otherTracks: [],
            probeIdentity: 'fake-prober-identity',
        );
    }
}
