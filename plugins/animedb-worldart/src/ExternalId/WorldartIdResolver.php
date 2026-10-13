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

namespace AnimeDb\Plugins\AnimedbWorldart\ExternalId;

/**
 * Matches the `world-art.ru`/`www.world-art.ru` host (schemes `http` and `https`) and extracts the
 * positive integer `id` from exactly one URL shape: `/animation/animation.php?id={id}`. Any other
 * page of a title (`animation_full_cast.php` and the like), the `/cinema/` section, any other
 * host or a non-positive/non-numeric `id` is deliberately not recognized.
 *
 * Used by {@see \AnimeDb\Plugins\AnimedbWorldart\WorldartFiller}.
 */
final class WorldartIdResolver
{
    private function __construct()
    {
    }

    /**
     * @param string[] $urls
     */
    public static function resolve(array $urls): ?string
    {
        foreach ($urls as $url) {
            if (!\is_string($url)) {
                continue;
            }

            $scheme = parse_url($url, \PHP_URL_SCHEME);
            if (!\is_string($scheme) || preg_match('/^https?$/i', $scheme) !== 1) {
                continue;
            }

            $host = parse_url($url, \PHP_URL_HOST);
            if (!\is_string($host) || preg_match('/^(www\.)?world-art\.ru$/i', $host) !== 1) {
                continue;
            }

            if (parse_url($url, \PHP_URL_PATH) !== '/animation/animation.php') {
                continue;
            }

            $query = parse_url($url, \PHP_URL_QUERY);
            if (!\is_string($query)) {
                continue;
            }
            parse_str($query, $params);
            if (isset($params['id']) && \is_string($params['id']) && preg_match('/^[1-9]\d*$/', $params['id']) === 1) {
                return $params['id'];
            }
        }

        return null;
    }
}
