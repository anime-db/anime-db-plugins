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

namespace AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture;

use AnimeDb\PluginContracts\Folder\RecordFolderFileState;
use AnimeDb\PluginContracts\Folder\RecordFolderWriterInterface;
use AnimeDb\PluginContracts\Model\AnimeId;

/**
 * In-memory {@see RecordFolderWriterInterface}: records every call and can be told to throw.
 */
final class FakeFolderWriter implements RecordFolderWriterInterface
{
    /** @var array<string, string> file name => contents */
    public array $files = [];

    /** @var list<array{string, bool}> [file name, overwrite] per write()/writeCover() call */
    public array $calls = [];

    public ?string $coverName = 'animedb-cover.jpg';

    /** @var array<string, RecordFolderFileState> */
    public array $states = [];

    public ?\Throwable $throwOnWriteCover = null;

    public ?\Throwable $throwOnWrite = null;

    public ?\Throwable $throwOnInspect = null;

    public function write(AnimeId $anime, string $fileName, string $contents, bool $overwrite = false): void
    {
        $this->calls[] = [$fileName, $overwrite];
        if ($this->throwOnWrite !== null) {
            throw $this->throwOnWrite;
        }

        $this->files[$fileName] = $contents;
    }

    public function writeCover(AnimeId $anime, string $baseName, bool $overwrite = false): ?string
    {
        $this->calls[] = [$baseName, $overwrite];
        if ($this->throwOnWriteCover !== null) {
            throw $this->throwOnWriteCover;
        }

        if ($this->coverName !== null) {
            $this->files[$this->coverName] = 'image';
        }

        return $this->coverName;
    }

    public function inspect(AnimeId $anime, string $fileName): RecordFolderFileState
    {
        if ($this->throwOnInspect !== null) {
            throw $this->throwOnInspect;
        }

        return $this->states[$fileName] ?? RecordFolderFileState::Absent;
    }
}
