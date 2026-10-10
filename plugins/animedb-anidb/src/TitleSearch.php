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

namespace AnimeDb\Plugins\AnimedbAnidb;

use AnimeDb\Plugins\AnimedbAnidb\Dump\DumpDownloader;
use AnimeDb\Plugins\AnimedbAnidb\Dump\DumpFiles;
use AnimeDb\Plugins\AnimedbAnidb\Dump\FileLock;
use AnimeDb\Plugins\AnimedbAnidb\Index\IndexBuilder;
use AnimeDb\Plugins\AnimedbAnidb\Index\IndexReader;

/**
 * Title search over the local dump: makes sure the dump is as fresh as the download rules allow
 * and the index matches it (both under one lock on a dedicated lock file), then queries the
 * index. When nothing needs doing — no download due and the index is built for the current dump
 * version — no lock is taken. A stale dump is searched as is; with neither dump nor index the
 * result is empty.
 */
final class TitleSearch
{
    public function __construct(
        private readonly DumpDownloader $downloader,
        private readonly IndexBuilder $builder,
        private readonly IndexReader $reader,
        private readonly DumpFiles $files,
        private readonly FileLock $lock,
    ) {
    }

    /**
     * @return list<array{aid: int, name: string}>
     */
    public function search(string $query, int $limit): array
    {
        if (!$this->isReady()) {
            $this->lock->withLock($this->files->lockPath(), function (): void {
                $this->downloader->refresh();
                $this->buildIndexIfNeeded();
            });
        }

        return $this->reader->search($query, $limit);
    }

    private function isReady(): bool
    {
        return !$this->downloader->isDue() && !$this->indexIsStale();
    }

    private function buildIndexIfNeeded(): void
    {
        if ($this->indexIsStale()) {
            $this->builder->build((string) $this->files->dumpVersion());
        }
    }

    private function indexIsStale(): bool
    {
        $version = $this->files->dumpVersion();

        return $version !== null && $this->reader->version() !== $version;
    }
}
