<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace AnimeDb\Plugins\Tools;

/**
 * Picks, out of a plugin's existing tags, the one {@see ReleaseNotesFormatter} should diff the
 * new release against: the tag with the greatest version strictly less than the version being
 * released, compared as semantic X.Y.Z versions rather than lexicographically or via `sort -V`
 * (see issue #28 — a naive string/`sort -V` comparison misorders e.g. "9.0.0" after "10.0.0").
 *
 * Tags are expected in `<plugin-id>/<X.Y.Z>` shape (the caller has already filtered the list
 * down to a single plugin); lines that do not parse as that shape are ignored rather than
 * failing the whole pick, since a stray/malformed tag elsewhere must not block release notes
 * generation.
 */
final class ReleaseNotesPreviousTagPicker
{
    private const VERSION_PATTERN = '/^(\d+)\.(\d+)\.(\d+)$/';

    /**
     * @param list<string> $tags one tag per line, `<plugin-id>/<X.Y.Z>` shape
     */
    public function pickPrevious(string $version, array $tags): ?string
    {
        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new \RuntimeException(\sprintf('"%s" is not a valid X.Y.Z version.', $version));
        }

        $bestTag = null;
        $bestVersion = null;

        foreach ($tags as $tag) {
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }

            $separatorPosition = strrpos($tag, '/');
            if ($separatorPosition === false) {
                continue;
            }

            $candidateVersion = substr($tag, $separatorPosition + 1);
            if (preg_match(self::VERSION_PATTERN, $candidateVersion) !== 1) {
                continue;
            }

            if (version_compare($candidateVersion, $version, '>=')) {
                continue;
            }

            if ($bestVersion === null || version_compare($candidateVersion, $bestVersion, '>')) {
                $bestVersion = $candidateVersion;
                $bestTag = $tag;
            }
        }

        return $bestTag;
    }
}
