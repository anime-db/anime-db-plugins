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

namespace AnimeDb\Plugins\Tools;

/**
 * One commit of the range {@see ReleaseNotesFormatter} turns into a release notes body,
 * already filtered by the caller to a single plugin's path (see issue #28) and carrying
 * whichever GitHub pull requests it is associated with (possibly none, possibly several).
 */
final class ReleaseNotesCommit
{
    /**
     * @param list<ReleaseNotesPullRequest> $pullRequests
     */
    public function __construct(
        public readonly string $sha,
        public readonly string $subject,
        public readonly string $author,
        public readonly array $pullRequests,
    ) {
    }
}
