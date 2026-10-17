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

namespace AnimeDb\Plugins\AnimedbFolderInfo\EventSubscriber;

use AnimeDb\PluginContracts\Background\BackgroundTask;
use AnimeDb\PluginContracts\Background\BackgroundTaskQueueInterface;
use AnimeDb\PluginContracts\Catalog\AnimeChangedEvent;
use AnimeDb\PluginContracts\Catalog\AnimeField;
use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\Plugins\AnimedbFolderInfo\Background\FolderTaskHandler;
use AnimeDb\Plugins\AnimedbFolderInfo\Settings\AutoFillSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Queues a `fill` task when the record's folder appears or changes, or when a field the
 * description shows (or the cover) changes. It renders and writes nothing itself, and does
 * nothing at all while the automation is switched off.
 */
final class AutoFillSubscriber implements EventSubscriberInterface
{
    /** The catalog fields that end up in `animedb-info.html` or in the cover file. */
    private const FIELDS = [
        AnimeField::Title,
        AnimeField::AlternativeNames,
        AnimeField::Type,
        AnimeField::Genres,
        AnimeField::Themes,
        AnimeField::EpisodesCount,
        AnimeField::Description,
        AnimeField::DatePremiere,
        AnimeField::DateEnd,
        AnimeField::DurationMinutes,
        AnimeField::Studios,
        AnimeField::Countries,
        AnimeField::Cover,
    ];

    public function __construct(
        private readonly AutoFillSettings $settings,
        private readonly BackgroundTaskQueueInterface $tasks,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AnimeChangedEvent::class => 'onChanged',
            AnimeFilesChangedEvent::class => 'onFilesChanged',
        ];
    }

    public function onChanged(AnimeChangedEvent $event): void
    {
        foreach (self::FIELDS as $field) {
            if (\array_key_exists($field->value, $event->changes)) {
                $this->submit($event->anime);

                return;
            }
        }
    }

    public function onFilesChanged(AnimeFilesChangedEvent $event): void
    {
        $queue = match ($event->reason) {
            // No folder at the moment of the event: nothing to write into.
            FilesChangeReason::Unlinked => false,
            default => true,
        };

        if ($queue) {
            $this->submit($event->anime);
        }
    }

    private function submit(AnimeId $anime): void
    {
        if ($this->settings->isAutoFillEnabled()) {
            $this->tasks->submit(new BackgroundTask(FolderTaskHandler::FILL, $anime));
        }
    }
}
