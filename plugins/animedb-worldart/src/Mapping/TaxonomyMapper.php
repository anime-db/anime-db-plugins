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

use AnimeDb\PluginContracts\Model\Demographic;
use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\ThemeCode;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;

/**
 * Genres, themes and the demographic by the NUMERIC ID of the site's dictionaries, not by the
 * name: the genre links carry `public_genre=N`, the tags (`div.newtag`) `public_keyword=N`, the
 * audience `public_target_audience=N`. The audience dictionary is never looked up in the genre
 * table: `2` is "боевые искусства" among genres, but "сэйнэн" among audiences.
 *
 * An id without a row and an unknown id are skipped; a code reached from several ids is given once.
 */
final class TaxonomyMapper
{
    /**
     * @return array{genres: list<GenreCode>, themes: list<ThemeCode>}
     */
    public static function genresAndThemes(WorldartPage $page): array
    {
        $links = [];
        $genres = $page->valueNode('Жанр');
        if ($genres !== null) {
            foreach ($genres->getElementsByTagName('a') as $link) {
                $links[] = $link;
            }
        }
        array_push($links, ...$page->tagLinks());

        $result = ['genres' => [], 'themes' => []];
        foreach ($links as $link) {
            $id = self::id((string) $link->getAttribute('href'), ['public_genre', 'public_keyword']);
            $code = $id === null ? null : GenreTable::MAP[$id] ?? null;
            if ($code instanceof GenreCode) {
                $result['genres'][$code->value] = $code;
            } elseif ($code instanceof ThemeCode) {
                $result['themes'][$code->value] = $code;
            }
        }

        return ['genres' => array_values($result['genres']), 'themes' => array_values($result['themes'])];
    }

    public static function demographic(WorldartPage $page): ?Demographic
    {
        $node = $page->valueNode('Целевая аудитория');
        if ($node === null) {
            return null;
        }

        foreach ($node->getElementsByTagName('a') as $link) {
            $id = self::id((string) $link->getAttribute('href'), ['public_target_audience']);
            if ($id !== null && isset(AudienceTable::BY_ID[$id])) {
                return AudienceTable::BY_ID[$id];
            }
        }

        // a value without a link: by the name from the audience table
        return AudienceTable::BY_NAME[mb_strtolower(WorldartPage::clean((string) $node->textContent))] ?? null;
    }

    /**
     * @param list<string> $params
     */
    private static function id(string $href, array $params): ?int
    {
        $query = parse_url($href, \PHP_URL_QUERY);
        if (!\is_string($query)) {
            return null;
        }
        parse_str($query, $values);
        foreach ($params as $param) {
            $value = $values[$param] ?? null;
            if (\is_string($value) && preg_match('/^[1-9]\d{0,9}$/', $value) === 1) {
                return (int) $value;
            }
        }

        return null;
    }
}
