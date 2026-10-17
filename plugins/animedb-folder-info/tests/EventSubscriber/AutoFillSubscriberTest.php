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

namespace AnimeDb\Plugins\AnimedbFolderInfo\Tests\EventSubscriber;

use AnimeDb\PluginContracts\Background\BackgroundTask;
use AnimeDb\PluginContracts\Catalog\AnimeChangedEvent;
use AnimeDb\PluginContracts\Catalog\AnimeField;
use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\FieldChange;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\Plugins\AnimedbFolderInfo\EventSubscriber\AutoFillSubscriber;
use AnimeDb\Plugins\AnimedbFolderInfo\Settings\AutoFillSettings;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeQueue;
use AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture\FakeSettingsStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AutoFillSubscriberTest extends TestCase
{
    private FakeQueue $queue;

    /**
     * @return iterable<string, array{AnimeField}>
     */
    public static function shownFields(): iterable
    {
        foreach (AnimeField::cases() as $field) {
            if ($field !== AnimeField::Demographic && $field !== AnimeField::Sources) {
                yield $field->value => [$field];
            }
        }
    }

    /**
     * @return iterable<string, array{FilesChangeReason}>
     */
    public static function queuingReasons(): iterable
    {
        yield 'created' => [FilesChangeReason::Created];
        yield 'files added' => [FilesChangeReason::FilesAdded];
        yield 'path changed' => [FilesChangeReason::PathChanged];
        yield 'download finished' => [FilesChangeReason::DownloadFinished];
    }

    #[DataProvider('shownFields')]
    public function testChangeOfAShownFieldQueuesFillOnly(AnimeField $field): void
    {
        $this->subscriber([])->onChanged(self::changed([$field->value => new FieldChange(1, 2)]));

        self::assertEquals([new BackgroundTask('fill', new AnimeId(5))], $this->queue->tasks);
    }

    public function testChangeOfOtherFieldsQueuesNothing(): void
    {
        $this->subscriber([])->onChanged(self::changed([
            AnimeField::Demographic->value => new FieldChange(null, null),
            AnimeField::Sources->value => new FieldChange([], ['x']),
        ]));

        self::assertSame([], $this->queue->tasks);
    }

    public function testOneShownFieldAmongOthersQueuesOneTask(): void
    {
        $this->subscriber([])->onChanged(self::changed([
            AnimeField::Sources->value => new FieldChange([], ['x']),
            AnimeField::Title->value => new FieldChange('a', 'b'),
            AnimeField::Cover->value => new FieldChange(null, 'c.jpg'),
        ]));

        self::assertCount(1, $this->queue->tasks);
    }

    #[DataProvider('queuingReasons')]
    public function testFilesChangeQueuesFill(FilesChangeReason $reason): void
    {
        $this->subscriber([])->onFilesChanged(new AnimeFilesChangedEvent(new AnimeId(5), $reason));

        self::assertEquals([new BackgroundTask('fill', new AnimeId(5))], $this->queue->tasks);
    }

    public function testUnlinkedQueuesNothing(): void
    {
        $this->subscriber([])->onFilesChanged(new AnimeFilesChangedEvent(new AnimeId(5), FilesChangeReason::Unlinked));

        self::assertSame([], $this->queue->tasks);
    }

    public function testSwitchedOffQueuesNothing(): void
    {
        $subscriber = $this->subscriber(['auto_fill' => false]);

        $subscriber->onChanged(self::changed([AnimeField::Title->value => new FieldChange('a', 'b')]));
        $subscriber->onFilesChanged(new AnimeFilesChangedEvent(new AnimeId(5), FilesChangeReason::Created));

        self::assertSame([], $this->queue->tasks);
    }

    public function testAutomationIsOnWhenNothingIsStored(): void
    {
        self::assertTrue((new AutoFillSettings(new FakeSettingsStore()))->isAutoFillEnabled());
        self::assertFalse((new AutoFillSettings(new FakeSettingsStore(['auto_fill' => false])))->isAutoFillEnabled());
    }

    public function testSubscribesToTheTwoEventsOnly(): void
    {
        self::assertSame(
            [AnimeChangedEvent::class, AnimeFilesChangedEvent::class],
            array_keys(AutoFillSubscriber::getSubscribedEvents()),
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function subscriber(array $settings): AutoFillSubscriber
    {
        $this->queue = new FakeQueue();

        return new AutoFillSubscriber(new AutoFillSettings(new FakeSettingsStore($settings)), $this->queue);
    }

    /**
     * @param array<string, FieldChange> $changes
     */
    private static function changed(array $changes): AnimeChangedEvent
    {
        return new AnimeChangedEvent(new AnimeId(5), $changes);
    }
}
