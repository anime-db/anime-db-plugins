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

use AnimeDb\PluginContracts\Model\AnimeName;
use AnimeDb\PluginContracts\Model\NameRole;

/**
 * Reads titles from AniDB's `/anime/titles/title` ONLY — never `//title`, which would also pick
 * up the titles of episodes.
 *
 * `type="main"` is the title's main name ({@see self::mainTitle()}); `type="kanareading"` and
 * untyped or unknown types are dropped; the rest become {@see AnimeName} with the matching
 * {@see NameRole}. `xml:lang` is passed through as the locale as is, except `x-*` values
 * (`x-jat` romaji and the like), which are not locales and map to `null`.
 */
final class TitleMapper
{
    /** @var array<string, NameRole> */
    private const ROLES = [
        'official' => NameRole::Official,
        'synonym' => NameRole::Synonym,
        'short' => NameRole::Short,
    ];

    public static function mainTitle(\SimpleXMLElement $card): ?string
    {
        foreach (self::titles($card) as $title) {
            if (self::type($title) === 'main') {
                $name = trim((string) $title);
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * @return list<AnimeName> alternative names, without the main one
     */
    public static function names(\SimpleXMLElement $card): array
    {
        $seen = [];
        $names = [];
        foreach (self::titles($card) as $title) {
            $role = self::ROLES[self::type($title)] ?? null;
            $name = trim((string) $title);
            if ($role === null || $name === '') {
                continue;
            }

            $locale = self::locale($title);
            $key = ($locale ?? '') . "\0" . $name;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $names[] = new AnimeName($name, $locale, $role);
        }

        return $names;
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private static function titles(\SimpleXMLElement $card): array
    {
        $titles = $card->xpath('/anime/titles/title');

        return $titles !== false && $titles !== null ? array_values($titles) : [];
    }

    private static function type(\SimpleXMLElement $title): string
    {
        return (string) $title['type'];
    }

    private static function locale(\SimpleXMLElement $title): ?string
    {
        $locale = trim((string) $title->attributes('http://www.w3.org/XML/1998/namespace')?->lang);

        return $locale === '' || str_starts_with(strtolower($locale), 'x-') ? null : $locale;
    }
}
