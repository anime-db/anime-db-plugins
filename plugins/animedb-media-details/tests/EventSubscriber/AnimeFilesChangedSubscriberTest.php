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

namespace AnimeDb\Plugins\AnimedbMediaDetails\Tests\EventSubscriber;

use AnimeDb\Plugins\AnimedbMediaDetails\EventSubscriber\AnimeFilesChangedSubscriber;
use AnimeDb\Plugins\AnimedbMediaDetails\Tests\Fixture\FakeBackgroundTaskQueue;
use AnimeDb\Plugins\AnimedbMediaDetails\Tests\Fixture\FakePluginDataStore;
use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use AnimeDb\PluginContracts\Model\AnimeId;
use PHPUnit\Framework\TestCase;

final class AnimeFilesChangedSubscriberTest extends TestCase
{
    public function testSubmitsTaskWhenPayloadIsNotEmpty(): void
    {
        $anime = new AnimeId(1);
        $store = new FakePluginDataStore();
        $store->seed($anime, ['files_total' => 1, 'files' => []]);
        $tasks = new FakeBackgroundTaskQueue();

        $subscriber = new AnimeFilesChangedSubscriber($store, $tasks);
        $subscriber->onFilesChanged(new AnimeFilesChangedEvent($anime, FilesChangeReason::FilesAdded));

        self::assertCount(1, $tasks->submitted);
        self::assertSame('probe-files', $tasks->submitted[0]->name);
        self::assertSame($anime, $tasks->submitted[0]->anime);
    }

    public function testDoesNotSubmitTaskWhenPayloadIsEmpty(): void
    {
        $anime = new AnimeId(2);
        $store = new FakePluginDataStore();
        $tasks = new FakeBackgroundTaskQueue();

        $subscriber = new AnimeFilesChangedSubscriber($store, $tasks);
        $subscriber->onFilesChanged(new AnimeFilesChangedEvent($anime, FilesChangeReason::Created));

        self::assertSame([], $tasks->submitted);
    }

    public function testSubscribesToAnimeFilesChangedEvent(): void
    {
        self::assertSame(
            [AnimeFilesChangedEvent::class => 'onFilesChanged'],
            AnimeFilesChangedSubscriber::getSubscribedEvents(),
        );
    }
}
