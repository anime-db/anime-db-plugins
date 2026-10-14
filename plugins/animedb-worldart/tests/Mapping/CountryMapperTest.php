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

use AnimeDb\Plugins\AnimedbWorldart\Mapping\CountryMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\CountryTable;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use AnimeDb\Plugins\AnimedbWorldart\Tests\Support\CardFactory;
use PHPUnit\Framework\TestCase;

final class CountryMapperTest extends TestCase
{
    public function testFixtures(): void
    {
        foreach (CardFactory::cards() as $fixture) {
            self::assertSame(['JP'], CountryMapper::map(CardFactory::fixture($fixture)), $fixture);
        }
    }

    public function testUnknownNameIsSkipped(): void
    {
        $page = CardFactory::fragment(['Производство' => "<a href='x'>Япония</a>, Атлантида, США,<br> Южная Корея , япония"]);

        self::assertSame(['JP', 'US', 'KR'], CountryMapper::map($page));
    }

    public function testOnlyTwoLetterCodesOfTheTable(): void
    {
        foreach (CountryTable::BY_NAME as $code) {
            self::assertMatchesRegularExpression('/^[A-Z]{2}$/', $code);
        }
        $page = CardFactory::fragment(['Производство' => 'Япония, Китай, Тайвань, Россия, Германия']);
        foreach (CountryMapper::map($page) ?? [] as $code) {
            self::assertContains($code, CountryTable::BY_NAME);
        }
    }

    public function testNothingKnownIsNull(): void
    {
        self::assertNull(CountryMapper::map(CardFactory::fragment(['Производство' => 'Атлантида'])));
        self::assertNull(CountryMapper::map(CardFactory::fragment([])));
        self::assertNull(CountryMapper::map(WorldartPage::fromBytes('')));
    }
}
