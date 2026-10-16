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

namespace AnimeDb\Plugins\AnimedbFolderInfo\Tests\Folder;

use AnimeDb\PluginContracts\Catalog\AnimeView;
use AnimeDb\PluginContracts\Folder\ForeignFileException;
use AnimeDb\PluginContracts\Folder\RecordFolderFileState;
use AnimeDb\PluginContracts\Folder\RecordHasNoFolderException;
use AnimeDb\PluginContracts\Folder\StorageNotWritableException;
use AnimeDb\PluginContracts\Media\StorageUnavailableException;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\Plugins\AnimedbFolderInfo\Folder\FolderService;
use AnimeDb\Plugins\AnimedbFolderInfo\Folder\FolderStatus;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeCatalog;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeFolderWriter;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakePluginDataStore;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\StubTwigFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FolderServiceTest extends TestCase
{
    private FakeFolderWriter $writer;
    private FakePluginDataStore $store;
    private FolderService $service;
    private AnimeId $anime;

    protected function setUp(): void
    {
        $this->writer = new FakeFolderWriter();
        $this->store = new FakePluginDataStore();
        $this->anime = new AnimeId(7);
        $view = new AnimeView('Title', [], null, [], [], null, ['https://s.example/1'], null);
        $this->service = new FolderService($this->writer, new FakeCatalog($view), $this->store, StubTwigFactory::create());
    }

    public function testWriteCreatesBothFilesAndRemembersCover(): void
    {
        self::assertSame(FolderStatus::Written, $this->service->write($this->anime, false));

        self::assertSame([['animedb-cover', false], ['animedb-info.html', false]], $this->writer->calls);
        self::assertArrayHasKey('animedb-info.html', $this->writer->files);
        self::assertStringContainsString('src="animedb-cover.jpg"', $this->writer->files['animedb-info.html']);
        self::assertSame('animedb-cover.jpg', $this->store->read($this->anime)['cover_file']);
        self::assertIsInt($this->service->writtenAt($this->anime));
    }

    public function testWriteWithoutCoverOmitsImage(): void
    {
        $this->writer->coverName = null;

        self::assertSame(FolderStatus::Written, $this->service->write($this->anime, false));
        self::assertStringNotContainsString('<img', $this->writer->files['animedb-info.html']);
        self::assertNull($this->store->read($this->anime)['cover_file']);
    }

    public function testOverwriteIsPassedToEveryFile(): void
    {
        $this->service->write($this->anime, true);

        self::assertSame([['animedb-cover', true], ['animedb-info.html', true]], $this->writer->calls);
    }

    public function testMissingRecordThrows(): void
    {
        $service = new FolderService($this->writer, new FakeCatalog(null), $this->store, StubTwigFactory::create());

        $this->expectException(\DomainException::class);
        $service->write($this->anime, false);
    }

    /**
     * @return iterable<string, array{\Throwable, FolderStatus}>
     */
    public static function writerExceptions(): iterable
    {
        yield 'foreign' => [new ForeignFileException(), FolderStatus::Modified];
        yield 'no folder' => [new RecordHasNoFolderException(), FolderStatus::NoFolder];
        yield 'unavailable' => [new StorageUnavailableException(), FolderStatus::Unavailable];
        yield 'not writable' => [new StorageNotWritableException(), FolderStatus::NotWritable];
    }

    #[DataProvider('writerExceptions')]
    public function testCoverWriteFailureCreatesNothing(\Throwable $e, FolderStatus $expected): void
    {
        $this->writer->throwOnWriteCover = $e;

        self::assertSame($expected, $this->service->write($this->anime, false));
        self::assertSame([], $this->writer->files);
        self::assertNull($this->service->writtenAt($this->anime));
    }

    #[DataProvider('writerExceptions')]
    public function testInfoWriteFailureIsReportedAndNotMarkedWritten(\Throwable $e, FolderStatus $expected): void
    {
        $this->writer->throwOnWrite = $e;

        self::assertSame($expected, $this->service->write($this->anime, false));
        self::assertArrayNotHasKey('animedb-info.html', $this->writer->files);
        self::assertNull($this->service->writtenAt($this->anime));
    }

    /**
     * @return iterable<string, array{\Throwable, FolderStatus}>
     */
    public static function inspectExceptions(): iterable
    {
        yield 'no folder' => [new RecordHasNoFolderException(), FolderStatus::NoFolder];
        yield 'unavailable' => [new StorageUnavailableException(), FolderStatus::Unavailable];
        yield 'not writable' => [new StorageNotWritableException(), FolderStatus::NotWritable];
    }

    #[DataProvider('inspectExceptions')]
    public function testStatusMapsInspectExceptions(\Throwable $e, FolderStatus $expected): void
    {
        $this->writer->throwOnInspect = $e;

        self::assertSame($expected, $this->service->status($this->anime));
    }

    /**
     * @return iterable<string, array{RecordFolderFileState, RecordFolderFileState, FolderStatus}>
     */
    public static function worstStates(): iterable
    {
        $w = RecordFolderFileState::Written;
        $a = RecordFolderFileState::Absent;
        $m = RecordFolderFileState::Modified;

        yield 'both written' => [$w, $w, FolderStatus::Written];
        yield 'cover absent' => [$w, $a, FolderStatus::Absent];
        yield 'info absent' => [$a, $w, FolderStatus::Absent];
        yield 'cover modified over absent' => [$a, $m, FolderStatus::Modified];
        yield 'info modified over written' => [$m, $w, FolderStatus::Modified];
    }

    #[DataProvider('worstStates')]
    public function testStatusIsTheWorstOfBothFiles(RecordFolderFileState $info, RecordFolderFileState $cover, FolderStatus $expected): void
    {
        $this->store->seed($this->anime, ['cover_file' => 'animedb-cover.png']);
        $this->writer->states = ['animedb-info.html' => $info, 'animedb-cover.png' => $cover];

        self::assertSame($expected, $this->service->status($this->anime));
    }

    public function testStatusInspectsOnlyInfoWhenNoCoverWasStored(): void
    {
        $this->writer->states = ['animedb-info.html' => RecordFolderFileState::Written];

        self::assertSame(FolderStatus::Written, $this->service->status($this->anime));
    }
}
