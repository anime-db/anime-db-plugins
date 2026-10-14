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

/**
 * The site's dictionary of the target audience (`public_target_audience`). Its ids overlap with
 * the genre and tag ids ({@see GenreTable}), so it is a table of its own.
 */
final class AudienceTable
{
    /** @var array<int, Demographic> */
    public const BY_ID = [
        1 => Demographic::Shounen, // сёнэн
        2 => Demographic::Seinen, // сэйнэн
        3 => Demographic::Shoujo, // сёдзё
        4 => Demographic::Josei, // дзёсэй
        5 => Demographic::Kids, // кодомо
    ];

    /** @var array<string, Demographic> lowercase name on the site => demographic, for a value without a link */
    public const BY_NAME = [
        'сёнэн' => Demographic::Shounen,
        'сэйнэн' => Demographic::Seinen,
        'сёдзё' => Demographic::Shoujo,
        'дзёсэй' => Demographic::Josei,
        'кодомо' => Demographic::Kids,
    ];
}
