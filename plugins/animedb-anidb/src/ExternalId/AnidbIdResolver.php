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

namespace AnimeDb\Plugins\AnimedbAnidb\ExternalId;

/**
 * Matches the `anidb.net`/`www.anidb.net` host (schemes `http` and `https`) and extracts the
 * numeric anime id (`aid`) from exactly three URL shapes: `/anime/{aid}`, `/a{aid}` and
 * `/perl-bin/animedb.pl?show=anime&aid={aid}`. Any other path, other `show` value or other
 * host is deliberately not recognized.
 *
 * Used by {@see \AnimeDb\Plugins\AnimedbAnidb\AnidbFiller}, which implements
 * `ExternalIdResolutionInterface` through `SearchByPluginInterface`.
 */
final class AnidbIdResolver
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
            if (!is_string($url)) {
                continue;
            }

            $scheme = parse_url($url, \PHP_URL_SCHEME);
            if (!is_string($scheme) || preg_match('/^https?$/i', $scheme) !== 1) {
                continue;
            }

            $host = parse_url($url, \PHP_URL_HOST);
            if (!is_string($host) || preg_match('/^(www\.)?anidb\.net$/i', $host) !== 1) {
                continue;
            }

            $path = parse_url($url, \PHP_URL_PATH);
            if (!is_string($path)) {
                continue;
            }

            if (preg_match('#^/(?:anime/|a)([1-9]\d*)$#', $path, $matches) === 1) {
                return $matches[1];
            }

            if ($path === '/perl-bin/animedb.pl') {
                $query = parse_url($url, \PHP_URL_QUERY);
                if (!is_string($query)) {
                    continue;
                }
                parse_str($query, $params);
                if (
                    ($params['show'] ?? null) === 'anime'
                    && isset($params['aid'])
                    && is_string($params['aid'])
                    && preg_match('/^[1-9]\d*$/', $params['aid']) === 1
                ) {
                    return $params['aid'];
                }
            }
        }

        return null;
    }
}
