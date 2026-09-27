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

namespace AnimeDb\Plugins\AnimedbMediaDetails\Tests\Fixture;

use AnimeDb\PluginContracts\Background\BackgroundTask;
use AnimeDb\PluginContracts\Background\BackgroundTaskQueueInterface;

/**
 * In-memory {@see BackgroundTaskQueueInterface} recording every submitted task, so a test
 * can assert whether {@see BackgroundTaskQueueInterface::submit()} was called at all.
 */
final class FakeBackgroundTaskQueue implements BackgroundTaskQueueInterface
{
    /** @var BackgroundTask[] */
    public array $submitted = [];

    public function submit(BackgroundTask $task): void
    {
        $this->submitted[] = $task;
    }
}
