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

namespace AnimeDb\Plugins\AnimedbWorldart\Tests\Mapping;

use AnimeDb\Plugins\AnimedbWorldart\Mapping\DateParser;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\DatesMapper;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use AnimeDb\Plugins\AnimedbWorldart\Tests\Support\CardFactory;
use PHPUnit\Framework\TestCase;

final class DatesMapperTest extends TestCase
{
    private static function fmt(?\DateTimeImmutable $date): ?string
    {
        return $date?->format('d.m.Y');
    }

    public function testUnknownPartsOfStartGoToBeginningOfPeriod(): void
    {
        self::assertSame('01.10.1999', self::fmt(DateParser::parseStart('??.10.1999')));
        self::assertSame('01.01.2001', self::fmt(DateParser::parseStart('??.??.2001')));
        self::assertSame('07.07.1998', self::fmt(DateParser::parseStart('07.07.1998')));
    }

    public function testUnknownPartsOfEndGoToEndOfPeriod(): void
    {
        self::assertSame('31.10.1999', self::fmt(DateParser::parseEnd('??.10.1999')));
        self::assertSame('31.12.2006', self::fmt(DateParser::parseEnd('??.??.2006')));
        self::assertSame('29.02.2024', self::fmt(DateParser::parseEnd('??.02.2024')));
        self::assertSame('29.09.1998', self::fmt(DateParser::parseEnd('29.09.1998')));
    }

    public function testInvalidDatesAreNull(): void
    {
        foreach (['', '??.??.????', '31.02.2000', '00.01.2000', '10.13.2000', '1.1.2000', 'abc', '??.??.0000'] as $raw) {
            self::assertNull(DateParser::parseStart($raw), $raw);
            self::assertNull(DateParser::parseEnd($raw), $raw);
        }
    }

    public function testCardWithUnknownPartsKeepsTheYear(): void
    {
        $dates = DatesMapper::map(CardFactory::fixture('card_1500.html'));

        self::assertSame('01.01.2001', self::fmt($dates['premiere']));
        self::assertSame('31.12.2006', self::fmt($dates['end']));
        self::assertLessThan($dates['end'], $dates['premiere']);
    }

    public function testReleaseRange(): void
    {
        $dates = DatesMapper::map(CardFactory::fixture('card_11466.html'));

        self::assertSame('29.09.2023', self::fmt($dates['premiere']));
        self::assertSame('22.03.2024', self::fmt($dates['end']));
    }

    public function testPremiereGivesOnlyPremiere(): void
    {
        $dates = DatesMapper::map(CardFactory::fixture('card_1.html'));

        self::assertSame('16.07.1988', self::fmt($dates['premiere']));
        self::assertNull($dates['end']);
    }

    public function testReleaseWithoutEndPart(): void
    {
        $dates = DatesMapper::map(CardFactory::fragment(['Выпуск' => 'c ??.10.1999']));

        self::assertSame('01.10.1999', self::fmt($dates['premiere']));
        self::assertNull($dates['end']);
    }

    public function testReleaseRangeWithLinksAndUnknownStartYear(): void
    {
        $dates = DatesMapper::map(CardFactory::fragment(['Выпуск' => "c <a href='x'>??.??.????</a> по <a href='y'>??.10.1999</a>"]));

        self::assertNull($dates['premiere']);
        self::assertSame('31.10.1999', self::fmt($dates['end']));
    }

    public function testNoLabelsIsNull(): void
    {
        self::assertSame(['premiere' => null, 'end' => null], DatesMapper::map(WorldartPage::fromBytes('')));
        self::assertSame(['premiere' => null, 'end' => null], DatesMapper::map(CardFactory::fragment(['Выпуск' => 'скоро'])));
    }
}
