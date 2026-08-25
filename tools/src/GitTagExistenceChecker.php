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
 * Real {@see TagExistenceChecker}, backed by `git ls-remote --exit-code`. Chosen over
 * shelling out to `gh` because `--exit-code` gives three distinguishable outcomes instead
 * of a single "did it exit non-zero" signal: `0` means the ref was found, `2` means the
 * remote was reached and the ref genuinely is not there, and anything else (network
 * failure, DNS, auth) is neither — it is a failed check, reported as
 * {@see TagExistenceCheckFailedException} rather than folded into "not found". It also
 * needs no `gh` binary and no token: the repository is public, so an anonymous fetch
 * against `origin` is enough.
 */
final class GitTagExistenceChecker implements TagExistenceChecker
{
    private const EXIT_CODE_REF_NOT_FOUND = 2;

    public function exists(string $pluginId, string $version): bool
    {
        $tag = $pluginId.'/'.$version;

        exec(
            'git ls-remote --exit-code --tags origin '.escapeshellarg('refs/tags/'.$tag).' 2>&1',
            $output,
            $exitCode,
        );

        return match ($exitCode) {
            0 => true,
            self::EXIT_CODE_REF_NOT_FOUND => false,
            default => throw new TagExistenceCheckFailedException(\sprintf('git ls-remote for tag "%s" exited with code %d: %s', $tag, $exitCode, implode("\n", $output))),
        };
    }
}
