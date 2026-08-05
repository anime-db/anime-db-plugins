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

namespace AnimeDb\Plugins\AnimedbShikimori\Mapping;

use AnimeDb\PluginContracts\Model\Demographic;
use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\ThemeCode;

/**
 * Routes Shikimori's `genres[]` into the contract's three disjoint axis enums.
 *
 * Deliberately does NOT trust Shikimori's own `genres[].kind` (`demographic`/`genre`/`theme`):
 * it disagrees with the contract axis on one entry ("Award Winning" is a Shikimori `theme` but
 * a contract {@see GenreCode}, because the contract axis follows MAL, not Shikimori — see the
 * class doc of {@see \AnimeDb\Plugins\AnimedbShikimori\ShikimoriFiller}). Instead each genre's
 * English `name` is slugified and tried against all three enums in a fixed order
 * (`GenreCode` → `ThemeCode` → `Demographic`) via `tryFrom()`; this places "Award Winning"
 * correctly without needing a special case. A name matching none of the three (Shikimori's
 * 18+-rated genres — Ecchi/Erotica/Hentai/Yaoi/Yuri — are intentionally absent from the
 * contract, plus any future Shikimori addition not yet in the contract) is dropped rather than
 * failing the whole lookup: `tryFrom()`, never `from()`, is used throughout for that reason.
 */
final class GenreMapper
{
    /**
     * @param list<array{name?: mixed}> $genres raw `genres[]` entries from the Shikimori response
     *
     * @return array{genres: list<GenreCode>, themes: list<ThemeCode>, demographics: list<Demographic>}
     */
    public static function map(array $genres): array
    {
        $result = ['genres' => [], 'themes' => [], 'demographics' => []];

        foreach ($genres as $genre) {
            $name = $genre['name'] ?? null;
            if (!\is_string($name) || $name === '') {
                continue;
            }

            $slug = self::slugify($name);

            $genreCode = GenreCode::tryFrom($slug);
            if ($genreCode !== null) {
                $result['genres'][] = $genreCode;
                continue;
            }

            $themeCode = ThemeCode::tryFrom($slug);
            if ($themeCode !== null) {
                $result['themes'][] = $themeCode;
                continue;
            }

            $demographic = Demographic::tryFrom($slug);
            if ($demographic !== null) {
                $result['demographics'][] = $demographic;
            }
        }

        return $result;
    }

    private static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = str_replace(['(', ')'], '', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }
}
