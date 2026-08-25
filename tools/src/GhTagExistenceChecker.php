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
 * Real {@see TagExistenceChecker}, backed by shelling out to the `gh` CLI — the same tool
 * `release.yml` itself uses to decide whether a tag is already published. The repository is
 * public, so the standard `GH_TOKEN`/`GITHUB_TOKEN` GitHub Actions already injects is enough;
 * no extra secret is needed.
 */
final class GhTagExistenceChecker implements TagExistenceChecker
{
    public function exists(string $pluginId, string $version): bool
    {
        $tag = $pluginId.'/'.$version;

        exec('gh release view '.escapeshellarg($tag).' >/dev/null 2>&1', $output, $exitCode);

        return $exitCode === 0;
    }
}
