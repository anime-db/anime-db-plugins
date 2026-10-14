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

use AnimeDb\PluginContracts\Model\AnimeName;
use AnimeDb\PluginContracts\Model\NameRole;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;

/**
 * The title and the alternative names of a card.
 *
 * The title is the Russian one from the heading, as on the site, with the season mark
 * (`Фрирен [ТВ-1]`): without the mark the seasons cannot be told apart. Romaji gets the locale
 * `null`, NOT `ja-Latn`: the host cuts the subtags off a locale, and `ja-Latn` would merge with
 * the kanji name into `ja` (the same reason `animedb-anidb` gives `x-jat` names no locale).
 * "Названия (прочие)" are separated by line breaks on the card; empty names and names equal to
 * the title are dropped.
 */
final class NamesMapper
{
    /** @var list<array{labels: list<string>, locale: ?string, role: NameRole, split: bool}> */
    private const SOURCES = [
        ['labels' => ['Название (англ.)'], 'locale' => 'en', 'role' => NameRole::Official, 'split' => false],
        ['labels' => ['Название (ромадзи)'], 'locale' => null, 'role' => NameRole::Official, 'split' => false],
        ['labels' => ['Название (кандзи)'], 'locale' => 'ja', 'role' => NameRole::Official, 'split' => false],
        ['labels' => ['Названия (прочие)', 'Название (прочие)'], 'locale' => null, 'role' => NameRole::Synonym, 'split' => true],
    ];

    public static function title(WorldartPage $page): ?string
    {
        return $page->title();
    }

    /**
     * @return list<AnimeName>|null
     */
    public static function alternativeNames(WorldartPage $page): ?array
    {
        $title = $page->title();
        $seen = [];
        $names = [];
        foreach (self::SOURCES as $source) {
            $node = $page->valueNode(...$source['labels']);
            if ($node === null) {
                continue;
            }

            $values = $source['split']
                ? explode("\n", WorldartPage::textWithBreaks($node))
                : [WorldartPage::clean((string) $node->textContent)];
            foreach ($values as $value) {
                $value = WorldartPage::clean($value);
                $key = ($source['locale'] ?? '')."\0".$value;
                if ($value === '' || $value === $title || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $names[] = new AnimeName($value, $source['locale'], $source['role']);
            }
        }

        return $names === [] ? null : $names;
    }
}
