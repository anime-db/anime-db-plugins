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

/**
 * Country names as the site writes them against ISO 3166-1 alpha-2 codes. The host accepts
 * only `[A-Z]{2}` and fails the whole entry on anything else, so a code is taken from here and
 * never computed; a name that is not in the table is not mapped.
 */
final class CountryTable
{
    /** @var array<string, string> */
    public const BY_NAME = [
        'Япония' => 'JP',
        'Китай' => 'CN',
        'Южная Корея' => 'KR',
        'Тайвань' => 'TW',
        'США' => 'US',
        'Канада' => 'CA',
        'Франция' => 'FR',
        'Германия' => 'DE',
        'Великобритания' => 'GB',
        'Россия' => 'RU',
    ];
}
