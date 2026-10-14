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

use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;

/**
 * "Производство": the names are matched exactly (after trimming) against {@see CountryTable};
 * a name that is not there is skipped. Only the codes from the table are returned, so the host
 * never gets a value outside `[A-Z]{2}`.
 */
final class CountryMapper
{
    /**
     * @return list<string>|null
     */
    public static function map(WorldartPage $page): ?array
    {
        $node = $page->valueNode('Производство');
        if ($node === null) {
            return null;
        }

        $codes = [];
        foreach (preg_split('/[,;\n]/u', WorldartPage::textWithBreaks($node)) ?: [] as $name) {
            $code = CountryTable::BY_NAME[WorldartPage::clean($name)] ?? null;
            if ($code !== null) {
                $codes[$code] = $code;
            }
        }

        return $codes === [] ? null : array_values($codes);
    }
}
