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

use AnimeDb\PluginContracts\Folder\RecordFolderIndexInterface;
use AnimeDb\PluginContracts\Model\AnimeId;

final class FakeFolderIndex implements RecordFolderIndexInterface
{
    /** @var list<array{?int, int}> [after, limit] per call */
    public array $calls = [];

    /**
     * @param list<int> $ids all the ids having a folder, ascending
     */
    public function __construct(public array $ids = [])
    {
    }

    public function recordsWithFolder(?AnimeId $after, int $limit): array
    {
        $this->calls[] = [$after?->value, $limit];

        $ids = array_values(array_filter($this->ids, static fn (int $id): bool => $after === null || $id > $after->value));

        return array_map(static fn (int $id): AnimeId => new AnimeId($id), \array_slice($ids, 0, $limit));
    }
}
