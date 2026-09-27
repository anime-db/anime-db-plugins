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

namespace AnimeDb\Plugins\AnimedbMediaDetails\EventSubscriber;

use AnimeDb\PluginContracts\Background\BackgroundTask;
use AnimeDb\PluginContracts\Background\BackgroundTaskQueueInterface;
use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\PluginData\PluginDataStoreInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Queues a probe of the changed record's files, but only when this plugin already
 * has data for it. The widget that first populates the payload runs in a separate
 * task (issue #154/#155); a record this plugin has never probed stays untouched
 * here, so a bulk change of files across the whole library (a moved storage
 * drive, a bulk unpack) does not queue a probe for every record the user may
 * never open.
 */
final class AnimeFilesChangedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PluginDataStoreInterface $store,
        private readonly BackgroundTaskQueueInterface $tasks,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [AnimeFilesChangedEvent::class => 'onFilesChanged'];
    }

    public function onFilesChanged(AnimeFilesChangedEvent $event): void
    {
        if ($this->store->read($event->anime) === []) {
            return;
        }

        $this->tasks->submit(new BackgroundTask('probe-files', $event->anime));
    }
}
