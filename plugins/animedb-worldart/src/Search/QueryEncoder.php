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

namespace AnimeDb\Plugins\AnimedbWorldart\Search;

/**
 * Encodes a search query into `windows-1251`, the only encoding the site's search accepts.
 * `//TRANSLIT//IGNORE` replaces or drops the characters that have no `windows-1251` form; a query of
 * hieroglyphs only turns into `????`, so "is there anything left to search by" is checked by the
 * presence of a letter or a digit, not by an empty string.
 */
final class QueryEncoder
{
    private function __construct()
    {
    }

    /**
     * @return string|null the bytes of the query in `windows-1251`, or null when there is nothing to search by
     */
    public static function encode(string $query): ?string
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $encoded = @iconv('UTF-8', 'CP1251//TRANSLIT//IGNORE', $query);
        if ($encoded === false) {
            return null;
        }

        $back = @iconv('CP1251', 'UTF-8//IGNORE', $encoded);
        if ($back === false || preg_match('/[\p{L}\p{N}]/u', $back) !== 1) {
            return null;
        }

        return $encoded;
    }
}
