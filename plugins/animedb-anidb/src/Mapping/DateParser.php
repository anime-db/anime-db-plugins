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

/**
 * Parses AniDB's `startdate`/`enddate`, which may be a full `YYYY-MM-DD`, a year-and-month
 * `YYYY-MM`, or a year-only `YYYY`.
 *
 * A partial `startdate` is completed to the BEGINNING of the period (`YYYY` → `YYYY-01-01`)
 * so the premiere year is not lost; a partial `enddate` is completed to the END of the period
 * (`YYYY` → `YYYY-12-31`, `YYYY-MM` → the month's last day) because the host treats a title as
 * already aired once `dateEnd <= now` and rejects `dateEnd < datePremiere` — rounding down
 * would make a title airing later in the year look finished on 1 January. A value that is
 * missing or matches none of the three shapes maps to `null`.
 */
final class DateParser
{
    public static function parseStart(?string $rawDate): ?\DateTimeImmutable
    {
        return self::parse($rawDate, endOfPeriod: false);
    }

    public static function parseEnd(?string $rawDate): ?\DateTimeImmutable
    {
        return self::parse($rawDate, endOfPeriod: true);
    }

    private static function parse(?string $rawDate, bool $endOfPeriod): ?\DateTimeImmutable
    {
        $rawDate = $rawDate !== null ? trim($rawDate) : '';
        if ($rawDate === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rawDate, $matches) === 1) {
            [, $year, $month, $day] = array_map('intval', $matches);

            return checkdate($month, $day, $year) ? self::toDate($rawDate) : null;
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $rawDate, $matches) === 1) {
            [, $year, $month] = array_map('intval', $matches);
            if ($month < 1 || $month > 12) {
                return null;
            }

            $firstDay = self::toDate(\sprintf('%04d-%02d-01', $year, $month));

            return $firstDay !== null && $endOfPeriod ? $firstDay->modify('last day of this month') : $firstDay;
        }

        if (preg_match('/^\d{4}$/', $rawDate) === 1) {
            return self::toDate($endOfPeriod ? \sprintf('%s-12-31', $rawDate) : \sprintf('%s-01-01', $rawDate));
        }

        return null;
    }

    private static function toDate(string $date): ?\DateTimeImmutable
    {
        $result = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $result !== false ? $result : null;
    }
}
