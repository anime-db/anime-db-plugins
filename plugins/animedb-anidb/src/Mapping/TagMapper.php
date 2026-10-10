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

use AnimeDb\PluginContracts\Model\Demographic;
use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\ThemeCode;

/**
 * Routes AniDB's `tags/tag` into the contract's three disjoint axis enums.
 *
 * A tag's English `name` is slugified, passed through an explicit synonym table (AniDB's own
 * wording where it differs from the contract's slug) and tried against all three enums in a
 * fixed order (`GenreCode` → `ThemeCode` → `Demographic`) via `tryFrom()`. A tag that matches
 * none is dropped rather than failing the whole card. Tags flagged `localspoiler` or
 * `globalspoiler` are dropped before matching. The tag weight is not considered: in AniDB
 * `weight="0"` means "not rated" or "applied implicitly via a child tag", not "does not apply"; hierarchy
 * service nodes (`themes`, `elements`, ...) fall out on `tryFrom()` anyway.
 */
final class TagMapper
{
    /** @var array<string, string> slugified AniDB tag name => contract slug */
    private const SYNONYMS = [
        'science-fiction' => 'sci-fi',
        'yuri' => 'girls-love',
        'shoujo-ai' => 'girls-love',
        'yaoi' => 'boys-love',
        'shounen-ai' => 'boys-love',
        'school-life' => 'school',
        'high-school' => 'school',
        'superpower' => 'super-power',
        'superpowers' => 'super-power',
        'magical-girl' => 'mahou-shoujo',
        'mahou-shojo' => 'mahou-shoujo',
        'shonen' => 'shounen',
        'shojo' => 'shoujo',
        'kodomo' => 'kids',
        'sport' => 'sports',
        'cooking' => 'gourmet',
        'food' => 'gourmet',
    ];

    /**
     * @return array{genres: list<GenreCode>, themes: list<ThemeCode>, demographics: list<Demographic>}
     */
    public static function map(\SimpleXMLElement $card): array
    {
        $result = ['genres' => [], 'themes' => [], 'demographics' => []];

        $tags = $card->xpath('/anime/tags/tag');
        foreach ($tags !== false && $tags !== null ? $tags : [] as $tag) {
            if (self::isTrue((string) $tag['localspoiler']) || self::isTrue((string) $tag['globalspoiler'])) {
                continue;
            }

            $slug = self::slugify((string) $tag->name);
            if ($slug === '') {
                continue;
            }
            $slug = self::SYNONYMS[$slug] ?? $slug;

            $genre = GenreCode::tryFrom($slug);
            if ($genre !== null) {
                $result['genres'][$genre->value] = $genre;
                continue;
            }

            $theme = ThemeCode::tryFrom($slug);
            if ($theme !== null) {
                $result['themes'][$theme->value] = $theme;
                continue;
            }

            $demographic = Demographic::tryFrom($slug);
            if ($demographic !== null) {
                $result['demographics'][$demographic->value] = $demographic;
            }
        }

        return [
            'genres' => array_values($result['genres']),
            'themes' => array_values($result['themes']),
            'demographics' => array_values($result['demographics']),
        ];
    }

    private static function isTrue(string $flag): bool
    {
        return strtolower(trim($flag)) === 'true';
    }

    private static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }
}
