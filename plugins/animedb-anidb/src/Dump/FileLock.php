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

namespace AnimeDb\Plugins\AnimedbAnidb\Dump;

/**
 * Exclusive advisory lock on a dedicated lock file. The lock file is never replaced through
 * `rename()` and is never the data file: a lock taken on a file that is itself swapped by
 * `rename()` can end up on an orphaned inode and stop excluding anyone.
 */
final class FileLock
{
    /**
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    public function withLock(string $lockPath, callable $action): mixed
    {
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the lock file.');
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException('Cannot take the lock.');
            }

            return $action();
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }
}
