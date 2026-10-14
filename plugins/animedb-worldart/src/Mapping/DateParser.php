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
 * Parses a date of the card, `dd.mm.yyyy`, where the day and the month may be `??`.
 *
 * The rule of `animedb-anidb`'s `DateParser`: an unknown part of the START date is completed to
 * the beginning of the period (`??.10.1999` → `01.10.1999`, `??.??.2001` → `01.01.2001`), of the
 * END date to the end of it (`??.10.1999` → `31.10.1999`, `??.??.2006` → `31.12.2006`). The year
 * is not lost and the end is not earlier than the start of the same period. An invalid date or
 * an unknown year is `null`.
 */
final class DateParser
{
    public static function parseStart(string $raw): ?\DateTimeImmutable
    {
        return self::parse($raw, endOfPeriod: false);
    }

    public static function parseEnd(string $raw): ?\DateTimeImmutable
    {
        return self::parse($raw, endOfPeriod: true);
    }

    private static function parse(string $raw, bool $endOfPeriod): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d{2}|\?\?)\.(\d{2}|\?\?)\.(\d{4})$/', trim($raw), $matches) !== 1) {
            return null;
        }
        [, $day, $month, $year] = $matches;
        $year = (int) $year;

        if ($month === '??') {
            return self::date($year, $endOfPeriod ? 12 : 1, $endOfPeriod ? 31 : 1);
        }
        $month = (int) $month;

        if ($day === '??') {
            $first = self::date($year, $month, 1);

            return $first !== null && $endOfPeriod ? $first->modify('last day of this month') : $first;
        }

        return self::date($year, $month, (int) $day);
    }

    private static function date(int $year, int $month, int $day): ?\DateTimeImmutable
    {
        if ($year < 1 || !checkdate($month, $day, $year)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', \sprintf('%04d-%02d-%02d', $year, $month, $day));

        return $date !== false ? $date : null;
    }
}
