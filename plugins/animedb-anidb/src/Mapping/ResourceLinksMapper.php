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

namespace AnimeDb\Plugins\AnimedbAnidb\Mapping;

/**
 * Page links of a card: its own AniDB page plus the `/anime/resources` entries of the types
 * that map to a page of the same title (`1` — ANN, `2` — MAL, `4` — official site).
 *
 * Every `<externalentity>` of a resource is taken. For types `1` and `2` the id is the first
 * `<identifier>` of the entity and only a positive integer counts; type `4` carries the address
 * in `<url>`. Entities that do not fit are skipped; resources of other types are ignored.
 * Built from the parsed XML on every call, so cached cards get the links too.
 */
final class ResourceLinksMapper
{
    private const ANN_URL = 'https://www.animenewsnetwork.com/encyclopedia/anime.php?id=';
    private const MAL_URL = 'https://myanimelist.net/anime/';
    private const MAX_URL_LENGTH = 2048;
    private const OWN_URL = 'https://anidb.net/anime/';

    /**
     * @return list<string>
     */
    public static function map(\SimpleXMLElement $card, int $aid): array
    {
        $urls = [self::OWN_URL.$aid];

        $resources = $card->xpath('/anime/resources/resource');
        foreach ($resources === false || $resources === null ? [] : $resources as $resource) {
            $type = (string) $resource['type'];
            if ($type !== '1' && $type !== '2' && $type !== '4') {
                continue;
            }

            foreach ($resource->externalentity as $entity) {
                if ($type === '4') {
                    $url = trim((string) $entity->url);
                    if (self::isSafeUrl($url)) {
                        $urls[] = $url;
                    }
                    continue;
                }

                $id = trim((string) $entity->identifier[0]);
                if (preg_match('/^[1-9]\d{0,9}$/', $id) !== 1) {
                    continue;
                }
                $urls[] = ($type === '1' ? self::ANN_URL : self::MAL_URL).$id;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * The address is user-edited AniDB data shown as a clickable link: only an absolute
     * http(s) URL with a host, no whitespace or control characters, bounded length.
     */
    private static function isSafeUrl(string $url): bool
    {
        if ($url === '' || \strlen($url) > self::MAX_URL_LENGTH
            || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        return $parts !== false
            && \in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }
}
