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
 * Scalar fields of an AniDB card: episode count, episode duration, studios and cover URL.
 *
 * Every value is read through a direct path from the `anime` root (`/anime/picture`, not
 * `//picture`, which would pick up character portraits).
 */
final class CardFieldsMapper
{
    private const COVER_URL = 'https://cdn-eu.anidb.net/images/main/';

    /**
     * `episodecount` as an integer, only if it is greater than 0.
     */
    public static function episodeCount(\SimpleXMLElement $card): ?int
    {
        $raw = self::first($card, '/anime/episodecount');
        if ($raw === null || preg_match('/^\d{1,9}$/', $raw) !== 1 || (int) $raw <= 0) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Median `length` (minutes) of regular episodes — `<epno type="1">`; specials and other
     * episode types are ignored.
     */
    public static function duration(\SimpleXMLElement $card): ?int
    {
        $lengths = [];
        foreach (self::all($card, '/anime/episodes/episode[epno/@type="1"]/length') as $length) {
            $raw = trim((string) $length);
            if (preg_match('/^\d{1,6}$/', $raw) === 1 && (int) $raw > 0) {
                $lengths[] = (int) $raw;
            }
        }

        if ($lengths === []) {
            return null;
        }

        sort($lengths);
        $count = \count($lengths);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $lengths[$middle] : (int) round(($lengths[$middle - 1] + $lengths[$middle]) / 2);
    }

    /**
     * @return list<string> names of `creators/name[@type="Animation Work"]`
     */
    public static function studios(\SimpleXMLElement $card): array
    {
        $studios = [];
        foreach (self::all($card, '/anime/creators/name[@type="Animation Work"]') as $name) {
            $value = trim((string) $name);
            if ($value !== '') {
                $studios[$value] = $value;
            }
        }

        return array_values($studios);
    }

    public static function coverUrl(\SimpleXMLElement $card): ?string
    {
        $picture = self::first($card, '/anime/picture');
        if ($picture === null || preg_match('/^[\w.-]+$/', $picture) !== 1) {
            return null;
        }

        return self::COVER_URL . $picture;
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private static function all(\SimpleXMLElement $card, string $path): array
    {
        $nodes = $card->xpath($path);

        return $nodes !== false && $nodes !== null ? array_values($nodes) : [];
    }

    private static function first(\SimpleXMLElement $card, string $path): ?string
    {
        $nodes = self::all($card, $path);
        if ($nodes === []) {
            return null;
        }

        $value = trim((string) $nodes[0]);

        return $value === '' ? null : $value;
    }
}
