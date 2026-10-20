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

namespace AnimeDb\Plugins\AnimedbWikidata\ExternalId;

use AnimeDb\Plugins\AnimedbWikidata\Source\SourceTable;

/**
 * Pure URL parsing for {@see \AnimeDb\Plugins\AnimedbWikidata\WikidataBridge::resolveExternalId()}:
 * no network, no database.
 *
 * A record that already carries a link to an anime catalog gets null: the bridge is needed only by
 * records without anime-catalog links. The catalogs are the hosts of the `catalog: true` rows of the
 * source table (with or without `www.`) plus Shikimori (any subdomain) and World-Art. Otherwise the first kind present wins, in this
 * priority, and inside one kind the first link in the order of `$urls`:
 *
 * 1. `wikidata.org/wiki/Q…` (also `m.wikidata.org` and `/entity/Q…`) gives `Q699835`;
 * 2. `imdb.com/title/tt…` (with `www.`/`m.`) gives `imdb:tt1568921`;
 * 3. `kinopoisk.ru/film/N` and `/series/N` gives `kinopoisk:492366`.
 *
 * The id is a search key, not an item id: it is not rewritten to `Q…` after the first linking, and
 * the later `findById()` accepts all three forms.
 */
final class WikidataIdResolver
{
    /**
     * Anime catalogs that have no property in Wikidata, so the source table cannot list them.
     */
    private const EXTRA_ANIME_CATALOG_HOSTS = '/^(?:(?:www\.)?world-art\.ru|(?:.+\.)?shikimori\.(?:io|one|org))$/i';

    private function __construct()
    {
    }

    /**
     * @param string[] $urls
     */
    public static function resolve(array $urls, ?SourceTable $table = null): ?string
    {
        $catalogHosts = ($table ?? SourceTable::default())->catalogHosts();
        $found = ['wikidata' => null, 'imdb' => null, 'kinopoisk' => null];

        foreach ($urls as $url) {
            if (!\is_string($url)) {
                continue;
            }
            $scheme = parse_url($url, \PHP_URL_SCHEME);
            $host = parse_url($url, \PHP_URL_HOST);
            if (!\is_string($scheme) || preg_match('/^https?$/i', $scheme) !== 1 || !\is_string($host)) {
                continue;
            }
            $host = rtrim($host, '.');

            if (\in_array(preg_replace('/^www\./i', '', strtolower($host)), $catalogHosts, true)
                || preg_match(self::EXTRA_ANIME_CATALOG_HOSTS, $host) === 1) {
                return null;
            }

            $path = parse_url($url, \PHP_URL_PATH);
            $path = \is_string($path) ? $path : '';

            if ($found['wikidata'] === null && preg_match('/^(?:www\.|m\.)?wikidata\.org$/i', $host) === 1
                && preg_match('#^/(?:wiki|entity)/(Q[1-9]\d*)/?$#', $path, $match) === 1) {
                $found['wikidata'] = $match[1];
            } elseif ($found['imdb'] === null && preg_match('/^(?:www\.|m\.)?imdb\.com$/i', $host) === 1
                && preg_match('#^/title/(tt\d+)(?:/.*)?$#', $path, $match) === 1) {
                $found['imdb'] = 'imdb:'.$match[1];
            } elseif ($found['kinopoisk'] === null && preg_match('/^(?:www\.)?kinopoisk\.ru$/i', $host) === 1
                && preg_match('#^/(?:film|series)/([1-9]\d*)(?:/.*)?$#', $path, $match) === 1) {
                $found['kinopoisk'] = 'kinopoisk:'.$match[1];
            }
        }

        return $found['wikidata'] ?? $found['imdb'] ?? $found['kinopoisk'];
    }
}
