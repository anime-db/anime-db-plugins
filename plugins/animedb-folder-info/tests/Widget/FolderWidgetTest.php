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

namespace AnimeDb\Plugins\AnimedbFolderInfo\Tests\Widget;

use AnimeDb\PluginContracts\Folder\RecordFolderFileState;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\Plugins\AnimedbFolderInfo\Folder\FolderService;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeCatalog;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeFolderWriter;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakePluginDataStore;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\StubTwigFactory;
use AnimeDb\Plugins\AnimedbFolderInfo\Widget\FolderWidget;
use PHPUnit\Framework\TestCase;

final class FolderWidgetTest extends TestCase
{
    public function testMetadata(): void
    {
        $metadata = FolderWidget::metadata();

        self::assertSame('folder', $metadata->name);
        self::assertSame('widget.folder.title', $metadata->titleKey);
        self::assertSame('widget.folder.description', $metadata->descriptionKey);
    }

    public function testOpeningTheCardInspectsAndNeverWrites(): void
    {
        $writer = new FakeFolderWriter();
        $store = new FakePluginDataStore();
        $store->seed(new AnimeId(7), ['cover_file' => 'animedb-cover.jpg', 'written_at' => 1700000000]);
        $writer->states = [
            'animedb-info.html' => RecordFolderFileState::Written,
            'animedb-cover.jpg' => RecordFolderFileState::Written,
        ];
        $twig = StubTwigFactory::create();
        $widget = new FolderWidget(new FolderService($writer, new FakeCatalog(), $store, $twig), $twig);

        $html = $widget->render(new AnimeId(7));

        self::assertStringContainsString('Written to the folder', $html);
        self::assertStringContainsString('2023-11-1', $html);
        self::assertStringContainsString('hx-post="/animedb_folder_info_write/7"', $html);
        self::assertSame([], $writer->calls);
    }

    public function testAbsentFilesShowWriteButton(): void
    {
        $twig = StubTwigFactory::create('ru');
        $widget = new FolderWidget(new FolderService(new FakeFolderWriter(), new FakeCatalog(), new FakePluginDataStore(), $twig), $twig);

        $html = $widget->render(new AnimeId(7));

        self::assertStringContainsString('Описание ещё не записано', $html);
        self::assertStringContainsString('Записать описание в папку', $html);
    }
}
