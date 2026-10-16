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


namespace AnimeDb\Plugins\AnimedbFolderInfo\Folder;

/**
 * What the widget shows for a record's folder. {@see self::Modified} also covers a file written
 * by someone else: the host cannot tell "edited by hand" from "never ours".
 */
enum FolderStatus: string
{
    case Written = 'written';
    case Absent = 'absent';
    case Modified = 'modified';
    case NoFolder = 'no_folder';
    case Unavailable = 'unavailable';
    case NotWritable = 'not_writable';

    /**
     * Whether the widget offers the write button; there is nothing to write into when the
     * record has no folder or the host refuses to write to it.
     */
    public function canWrite(): bool
    {
        return match ($this) {
            self::NoFolder, self::NotWritable => false,
            default => true,
        };
    }
}
