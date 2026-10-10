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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\Mapping;

use AnimeDb\Plugins\AnimedbAnidb\Mapping\DateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DateParserTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?string, 2: ?string}> raw, start, end
     */
    public static function dateProvider(): iterable
    {
        yield 'year only' => ['2018', '2018-01-01', '2018-12-31'];
        yield 'year and month' => ['2018-05', '2018-05-01', '2018-05-31'];
        yield 'april has 30 days' => ['2018-04', '2018-04-01', '2018-04-30'];
        yield 'february' => ['2019-02', '2019-02-01', '2019-02-28'];
        yield 'february of a leap year' => ['2020-02', '2020-02-01', '2020-02-29'];
        yield 'full date' => ['2003-08-25', '2003-08-25', '2003-08-25'];
        yield 'invalid month' => ['2018-13', null, null];
        yield 'invalid day' => ['2019-02-30', null, null];
        yield 'garbage' => ['soon', null, null];
        yield 'two-digit year' => ['18', null, null];
        yield 'empty' => ['', null, null];
        yield 'null' => [null, null, null];
    }

    #[DataProvider('dateProvider')]
    public function testParse(?string $raw, ?string $start, ?string $end): void
    {
        self::assertSame($start, DateParser::parseStart($raw)?->format('Y-m-d'));
        self::assertSame($end, DateParser::parseEnd($raw)?->format('Y-m-d'));
    }

    public function testLiveFixtureWithPartialEndDate(): void
    {
        $card = FixtureCard::load(14500);

        self::assertSame('2018-05-14', DateParser::parseStart((string) $card->startdate)?->format('Y-m-d'));
        self::assertSame('2018-12-31', DateParser::parseEnd((string) $card->enddate)?->format('Y-m-d'));
    }

    public function testLiveFixtureWithPartialDates(): void
    {
        $card = FixtureCard::load(7000);

        self::assertSame('1988-01-01', DateParser::parseStart((string) $card->startdate)?->format('Y-m-d'));
        self::assertSame('1988-12-31', DateParser::parseEnd((string) $card->enddate)?->format('Y-m-d'));
    }
}
