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

namespace AnimeDb\Plugins\AnimedbWorldart\Mapping;

/**
 * An address taken from a page is shown as a clickable link: only an absolute http(s) URL with a
 * host, no whitespace, control characters, backslashes or userinfo, bounded length (as `ResourceLinksMapper::isSafeUrl()`
 * of `animedb-anidb`).
 */
final class UrlGuard
{
    private const MAX_LENGTH = 2048;

    public static function isSafe(string $url): bool
    {
        if ($url === '' || \strlen($url) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F\\\\]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        // userinfo is never part of a legitimate link; it is how the host gets spoofed
        return $parts !== false
            && !isset($parts['user']) && !isset($parts['pass'])
            && \in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }
}
