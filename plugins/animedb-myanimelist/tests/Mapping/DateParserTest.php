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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Tests\Mapping;

use AnimeDb\Plugins\AnimedbMyanimelist\Mapping\DateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DateParserTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?string}>
     */
    public static function startDateProvider(): iterable
    {
        yield 'full date is returned as is' => ['2008-04-04', '2008-04-04'];
        yield 'year-month completes to the first day of the month' => ['2027-10', '2027-10-01'];
        yield 'year-only completes to the first day of the year' => ['2027', '2027-01-01'];
        yield 'null is null' => [null, null];
        yield 'empty string is null' => ['', null];
        yield 'garbage is null' => ['not-a-date', null];
        yield 'out-of-range month is null' => ['2027-13', null];
        yield 'out-of-range day is null' => ['2027-02-30', null];
    }

    #[DataProvider('startDateProvider')]
    public function testParseStart(?string $rawDate, ?string $expected): void
    {
        self::assertSame($expected, DateParser::parseStart($rawDate)?->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?string}>
     */
    public static function endDateProvider(): iterable
    {
        yield 'full date is returned as is' => ['2008-09-26', '2008-09-26'];
        yield 'year-month completes to the last day of the month' => ['2027-10', '2027-10-31'];
        yield 'year-month completes to the last day of february in a leap year' => ['2028-02', '2028-02-29'];
        yield 'year-month completes to the last day of february in a non-leap year' => ['2027-02', '2027-02-28'];
        yield 'year-only completes to the last day of the year' => ['2027', '2027-12-31'];
        yield 'null is null' => [null, null];
        yield 'empty string is null' => ['', null];
        yield 'garbage is null' => ['not-a-date', null];
        yield 'out-of-range month is null' => ['2027-13', null];
        yield 'out-of-range day is null' => ['2027-02-30', null];
    }

    #[DataProvider('endDateProvider')]
    public function testParseEnd(?string $rawDate, ?string $expected): void
    {
        self::assertSame($expected, DateParser::parseEnd($rawDate)?->format('Y-m-d'));
    }

    /**
     * card_59068 fixture (issue #177) is a live MyAnimeList response with an incomplete
     * `start_date` ("2027-10") and no `end_date` field at all — exactly the case the issue
     * warns about: rounding the start down must not discard the premiere year.
     */
    public function testIncompleteStartDateFromLiveFixtureKeepsThePremiereYear(): void
    {
        $fixture = self::loadFixture('card_59068.json');

        self::assertSame('2027-10', $fixture['start_date']);
        self::assertArrayNotHasKey('end_date', $fixture);

        self::assertSame('2027-10-01', DateParser::parseStart($fixture['start_date'])?->format('Y-m-d'));
        self::assertNull(DateParser::parseEnd($fixture['end_date'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadFixture(string $name): array
    {
        $path = \dirname(__DIR__).'/Fixture/'.$name;

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
