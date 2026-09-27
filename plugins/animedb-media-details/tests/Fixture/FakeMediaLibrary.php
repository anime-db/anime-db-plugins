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

use AnimeDb\PluginContracts\Media\MediaFile;
use AnimeDb\PluginContracts\Media\MediaLibraryInterface;
use AnimeDb\PluginContracts\Media\StorageUnavailableException;
use AnimeDb\PluginContracts\Model\AnimeId;

/**
 * In-memory {@see MediaLibraryInterface} that returns whatever {@see MediaFile} handles it
 * was told to, so a test can assert the handler probes exactly those object instances rather
 * than any file it fabricates itself.
 */
final class FakeMediaLibrary implements MediaLibraryInterface
{
    /** @var array<int, MediaFile[]> */
    private array $files = [];

    /** @var array<int, true> */
    private array $unavailable = [];

    public int $listFilesCallCount = 0;

    /**
     * @param MediaFile[] $files
     */
    public function willReturn(AnimeId $anime, array $files): void
    {
        $this->files[$anime->value] = $files;
    }

    public function willThrowStorageUnavailable(AnimeId $anime): void
    {
        $this->unavailable[$anime->value] = true;
    }

    public function listFiles(AnimeId $anime): array
    {
        ++$this->listFilesCallCount;

        if (isset($this->unavailable[$anime->value])) {
            throw new StorageUnavailableException('Simulated storage outage.');
        }

        return $this->files[$anime->value] ?? [];
    }
}
