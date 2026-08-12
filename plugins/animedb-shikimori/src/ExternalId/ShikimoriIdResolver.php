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

namespace AnimeDb\Plugins\AnimedbShikimori\ExternalId;

/**
 * Matches Shikimori domains (`shikimori.io`/`.one`/`.org`, including subdomains) and a path of
 * the form `/animes/<id>` or `/animes/z<id>-<slug>`, returning the numeric id.
 *
 * Shared by every role interface that must implement `resolveExternalId()` against this
 * plugin's own vendor: {@see \AnimeDb\Plugins\AnimedbShikimori\ShikimoriFiller} (extends
 * `ExternalIdResolutionInterface` through `SyncInterface`) and the phase 5 widgets
 * ({@see \AnimeDb\Plugins\AnimedbShikimori\Widget\RelatedWidget},
 * {@see \AnimeDb\Plugins\AnimedbShikimori\Widget\SimilarWidget},
 * {@see \AnimeDb\Plugins\AnimedbShikimori\Widget\NewWidget}) — extracted here instead of
 * duplicated per class so the URL pattern is defined once.
 */
final class ShikimoriIdResolver
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

            $host = parse_url($url, \PHP_URL_HOST);
            if (!is_string($host) || preg_match('/(^|\.)shikimori\.(io|one|org)$/i', $host) !== 1) {
                continue;
            }

            $path = parse_url($url, \PHP_URL_PATH);
            if (is_string($path) && preg_match('#/animes/z?(\d+)#', $path, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}
