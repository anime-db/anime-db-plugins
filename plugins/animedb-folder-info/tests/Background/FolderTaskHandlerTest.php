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

namespace AnimeDb\Plugins\AnimedbFolderInfo\Tests\Background;

use AnimeDb\PluginContracts\Background\BackgroundTask;
use AnimeDb\PluginContracts\Catalog\AnimeView;
use AnimeDb\PluginContracts\Folder\ForeignFileException;
use AnimeDb\PluginContracts\Folder\RecordHasNoFolderException;
use AnimeDb\PluginContracts\Folder\StorageNotWritableException;
use AnimeDb\PluginContracts\Media\StorageUnavailableException;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\Plugins\AnimedbFolderInfo\Background\FolderTaskHandler;
use AnimeDb\Plugins\AnimedbFolderInfo\Folder\FolderService;
use AnimeDb\Plugins\AnimedbFolderInfo\Settings\AutoFillSettings;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeCatalog;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeFolderIndex;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeFolderWriter;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakePluginDataStore;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeQueue;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeSettingsStore;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\StubTwigFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FolderTaskHandlerTest extends TestCase
{
    private FakeFolderWriter $writer;
    private FakeFolderIndex $index;
    private FakeQueue $queue;
    private FakeSettingsStore $store;
    private AutoFillSettings $settings;
    private FakeCatalog $catalog;
    private FolderTaskHandler $handler;

    protected function setUp(): void
    {
        $this->writer = new FakeFolderWriter();
        $this->index = new FakeFolderIndex();
        $this->queue = new FakeQueue();
        $this->store = new FakeSettingsStore(['auto_fill' => false]);
        $this->settings = new AutoFillSettings($this->store);
        $this->catalog = new FakeCatalog(new AnimeView('Title', [], null, [], [], null, [], null));
        $folder = new FolderService($this->writer, $this->catalog, new FakePluginDataStore(), StubTwigFactory::create());
        $this->handler = new FolderTaskHandler($folder, $this->index, $this->settings, $this->queue);
    }

    public function testFillWritesWithoutOverwrite(): void
    {
        $this->handler->handle(new BackgroundTask('fill', new AnimeId(3)));

        self::assertArrayHasKey('animedb-info.html', $this->writer->files);
        self::assertNotSame([], $this->writer->calls);
        foreach ($this->writer->calls as [, $overwrite]) {
            self::assertFalse($overwrite);
        }
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function outcomes(): iterable
    {
        yield 'modified' => [new ForeignFileException()];
        yield 'no folder' => [new RecordHasNoFolderException()];
        yield 'not writable' => [new StorageNotWritableException()];
        yield 'unavailable' => [new StorageUnavailableException()];
    }

    #[DataProvider('outcomes')]
    public function testFillSkipsFolderProblemsSilently(\Throwable $e): void
    {
        $this->writer->throwOnWriteCover = $e;

        $this->handler->handle(new BackgroundTask('fill', new AnimeId(3)));

        self::assertSame([], $this->writer->files);
        self::assertSame([], $this->queue->tasks);
    }

    public function testFillOfAVanishedRecordExitsSilently(): void
    {
        $this->catalog->view = null;

        $this->handler->handle(new BackgroundTask('fill', new AnimeId(3)));

        self::assertSame([], $this->writer->calls);
    }

    public function testFillAllOfAnotherRunDoesNothing(): void
    {
        $this->store->data += ['run_id' => 'current', 'run_cursor' => null];
        $this->index->ids = [1, 2];

        $this->handler->handle(self::fillAll('old', null));

        self::assertSame([], $this->index->calls);
        self::assertSame([], $this->writer->calls);
        self::assertSame([], $this->queue->tasks);
    }

    public function testFillAllWithAnOutdatedCursorDoesNothing(): void
    {
        $this->store->data += ['run_id' => 'r', 'run_cursor' => 50];
        $this->index->ids = [1, 2];

        $this->handler->handle(self::fillAll('r', 10));
        $this->handler->handle(self::fillAll('r', null));

        self::assertSame([], $this->index->calls);
        self::assertSame([], $this->queue->tasks);
    }

    public function testFillAllTakesFiftyAfterTheCursorAndQueuesTheNextBatch(): void
    {
        $this->store->data += ['run_id' => 'r', 'run_cursor' => 100];
        $this->index->ids = range(101, 175);
        $counters = ['written' => 4, 'modified' => 1, 'no_folder' => 0, 'unavailable' => 0, 'not_writable' => 0, 'failed' => 0];

        $this->handler->handle(self::fillAll('r', 100, $counters));

        self::assertSame([[100, 50]], $this->index->calls);
        self::assertSame(150, $this->store->data['run_cursor']);
        self::assertCount(1, $this->queue->tasks);
        $next = $this->queue->tasks[0];
        self::assertSame('fill-all', $next->name);
        self::assertSame(['written' => 54, 'modified' => 1, 'no_folder' => 0, 'unavailable' => 0, 'not_writable' => 0, 'failed' => 0], $next->payload['counters']);
        self::assertSame('r', $next->payload['run']);
        self::assertSame(150, $next->payload['after']);
        self::assertSame(
            $next->payload,
            json_decode((string) json_encode($next->payload), true),
            'The payload must survive a JSON round trip.',
        );
        foreach ($this->writer->calls as [, $overwrite]) {
            self::assertFalse($overwrite);
        }
    }

    public function testFillAllCountsEveryOutcome(): void
    {
        $this->store->data += ['run_id' => 'r', 'run_cursor' => null];
        // 1 written, 2 modified, 3 no folder, 4 unavailable, 5 not writable, 6 failed: distinct counts.
        $throwables = [
            2 => new ForeignFileException(),
            3 => new RecordHasNoFolderException(),
            4 => new StorageUnavailableException(),
            5 => new StorageNotWritableException(),
            6 => new \RuntimeException('boom'),
        ];
        $id = 0;
        $ids = [];
        foreach ($throwables as $count => $throwable) {
            for ($i = 0; $i < $count; ++$i) {
                $ids[] = ++$id;
                $this->writer->throwOnWriteCoverFor[$id] = $throwable;
            }
        }
        $ids[] = ++$id;
        $this->index->ids = $ids;

        $this->handler->handle(self::fillAll('r', null));

        self::assertSame(
            ['written' => 1, 'modified' => 2, 'no_folder' => 3, 'unavailable' => 4, 'not_writable' => 5, 'failed' => 6],
            $this->queue->tasks[0]->payload['counters'],
        );
    }

    public function testFailureOfOneRecordDoesNotStopTheRun(): void
    {
        $this->store->data += ['run_id' => 'r', 'run_cursor' => null];
        $this->index->ids = [1, 2, 3];
        $this->writer->throwOnWriteCoverFor[2] = new \RuntimeException('boom');

        $this->handler->handle(self::fillAll('r', null));

        self::assertSame(3, $this->store->data['run_cursor']);
        self::assertCount(1, $this->queue->tasks);
        self::assertSame(
            ['written' => 2, 'modified' => 0, 'no_folder' => 0, 'unavailable' => 0, 'not_writable' => 0, 'failed' => 1],
            $this->queue->tasks[0]->payload['counters'],
        );

        $this->handler->handle($this->queue->tasks[0]);

        self::assertCount(1, $this->queue->tasks);
        self::assertNotNull($this->settings->lastRun());
        self::assertSame(1, $this->settings->lastRun()['failed']);
    }

    public function testFillFailureDoesNotFailTheTask(): void
    {
        $this->writer->throwOnWriteCover = new \RuntimeException('boom');

        $this->handler->handle(new BackgroundTask('fill', new AnimeId(3)));

        self::assertSame([], $this->queue->tasks);
    }

    public function testEmptyBatchStoresTheSummaryAndQueuesNothing(): void
    {
        $this->store->data += ['run_id' => 'r', 'run_cursor' => 7];
        $counters = ['written' => 5, 'modified' => 4, 'no_folder' => 3, 'unavailable' => 2, 'not_writable' => 1, 'failed' => 6];

        $this->handler->handle(self::fillAll('r', 7, $counters));

        self::assertSame([], $this->queue->tasks);
        $last = $this->settings->lastRun();
        self::assertNotNull($last);
        self::assertSame(['written' => 5, 'modified' => 4, 'no_folder' => 3, 'unavailable' => 2, 'not_writable' => 1, 'failed' => 6], array_diff_key($last, ['finished_at' => 1]));
        self::assertGreaterThan(0, $last['finished_at']);
    }

    public function testNewClickResetsTheCursorAndStopsTheOldChain(): void
    {
        $this->store->data += ['run_id' => 'old', 'run_cursor' => 50];
        $this->index->ids = [1, 2];

        $new = $this->settings->startRun();

        self::assertNull($this->store->data['run_cursor']);
        $this->handler->handle(self::fillAll('old', 50));
        self::assertSame([], $this->queue->tasks);

        $this->handler->handle(self::fillAll($new, null));
        self::assertSame([[null, 50]], $this->index->calls);
        self::assertCount(1, $this->queue->tasks);
    }

    public function testFillAllWorksWhileTheAutomationIsOff(): void
    {
        self::assertFalse($this->settings->isAutoFillEnabled());
        $this->store->data += ['run_id' => 'r', 'run_cursor' => null];
        $this->index->ids = [1];

        $this->handler->handle(self::fillAll('r', null));

        self::assertArrayHasKey('animedb-info.html', $this->writer->files);
    }

    public function testClickDuringABatchDoesNotLetTheOldChainContinue(): void
    {
        $this->store->data += ['run_id' => 'r', 'run_cursor' => null];
        $this->index->ids = [1, 2, 3];
        $clicked = false;
        $this->writer->onWriteCover = function (AnimeId $anime) use (&$clicked): void {
            if ($anime->value === 2) {
                $clicked = true;
                $this->settings->startRun();
            }
        };

        $this->handler->handle(self::fillAll('r', null));

        self::assertTrue($clicked);
        self::assertNotSame('r', $this->store->data['run_id']);
        self::assertNull($this->store->data['run_cursor']);
        self::assertSame([], $this->queue->tasks);
    }

    /**
     * @param array<string, int>|null $counters
     */
    private static function fillAll(string $run, ?int $after, ?array $counters = null): BackgroundTask
    {
        return new BackgroundTask('fill-all', payload: [
            'run' => $run,
            'after' => $after,
            'counters' => $counters ?? FolderTaskHandler::ZERO_COUNTERS,
        ]);
    }
}
