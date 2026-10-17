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

namespace AnimeDb\Plugins\AnimedbFolderInfo\Background;

use AnimeDb\PluginContracts\Background\BackgroundTask;
use AnimeDb\PluginContracts\Background\BackgroundTaskHandlerInterface;
use AnimeDb\PluginContracts\Background\BackgroundTaskQueueInterface;
use AnimeDb\PluginContracts\Folder\RecordFolderIndexInterface;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\Plugins\AnimedbFolderInfo\Folder\FolderService;
use AnimeDb\Plugins\AnimedbFolderInfo\Folder\FolderStatus;
use AnimeDb\Plugins\AnimedbFolderInfo\Settings\AutoFillSettings;

/**
 * The plugin's single {@see BackgroundTaskHandlerInterface}: `fill` writes one record, `fill-all`
 * walks the records that have a folder in batches.
 *
 * Both only ever write with `overwrite=false`, so a file that is not ours or was edited is left
 * alone; replacing it is the widget button's job. Both are safe to run any number of times: the
 * host does not touch the disk when the bytes already match.
 */
final class FolderTaskHandler implements BackgroundTaskHandlerInterface
{
    public const FILL = 'fill';
    public const FILL_ALL = 'fill-all';
    public const BATCH_SIZE = 50;

    public const ZERO_COUNTERS = [
        'written' => 0,
        'modified' => 0,
        'no_folder' => 0,
        'unavailable' => 0,
        'not_writable' => 0,
    ];

    public function __construct(
        private readonly FolderService $folder,
        private readonly RecordFolderIndexInterface $index,
        private readonly AutoFillSettings $settings,
        private readonly BackgroundTaskQueueInterface $tasks,
    ) {
    }

    public function handle(BackgroundTask $task): void
    {
        match ($task->name) {
            self::FILL => $task->anime !== null ? $this->fill($task->anime) : null,
            self::FILL_ALL => $this->fillAll($task->payload),
            default => null,
        };
    }

    /**
     * @return FolderStatus|null null if the record no longer exists
     */
    private function fill(AnimeId $anime): ?FolderStatus
    {
        try {
            return $this->folder->write($anime, false);
        } catch (\DomainException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fillAll(array $payload): void
    {
        $run = $payload['run'] ?? null;
        $after = $payload['after'] ?? null;

        // A stale chain (a newer click) or a redelivered batch (an outdated cursor) stops here.
        if (!\is_string($run) || !$this->settings->isCurrent($run, $after)) {
            return;
        }

        $counters = self::counters($payload['counters'] ?? null);
        $batch = $this->index->recordsWithFolder($after === null ? null : new AnimeId($after), self::BATCH_SIZE);

        if ($batch === []) {
            $this->settings->finish($run, $counters, time());

            return;
        }

        foreach ($batch as $anime) {
            $counter = match ($this->fill($anime)) {
                FolderStatus::Written => 'written',
                FolderStatus::Modified => 'modified',
                FolderStatus::NoFolder => 'no_folder',
                FolderStatus::Unavailable => 'unavailable',
                FolderStatus::NotWritable => 'not_writable',
                default => null,
            };
            if ($counter !== null) {
                ++$counters[$counter];
            }
        }

        $cursor = $batch[array_key_last($batch)]->value;
        if (!$this->settings->advance($run, $cursor)) {
            return;
        }

        $this->tasks->submit(new BackgroundTask(self::FILL_ALL, payload: [
            'run' => $run,
            'after' => $cursor,
            'counters' => $counters,
        ]));
    }

    /**
     * @return array<string, int>
     */
    private static function counters(mixed $raw): array
    {
        $counters = self::ZERO_COUNTERS;
        if (\is_array($raw)) {
            foreach ($counters as $key => $_) {
                if (\is_int($raw[$key] ?? null)) {
                    $counters[$key] = $raw[$key];
                }
            }
        }

        return $counters;
    }
}
