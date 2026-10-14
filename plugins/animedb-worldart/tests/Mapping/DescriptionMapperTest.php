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

use AnimeDb\Plugins\AnimedbWorldart\Mapping\DescriptionMapper;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use AnimeDb\Plugins\AnimedbWorldart\Tests\Support\CardFactory;
use PHPUnit\Framework\TestCase;

final class DescriptionMapperTest extends TestCase
{
    public function testOnlyRuKeyAndNoMarkupNoCopyright(): void
    {
        foreach (['card_11466.html', 'card_1.html'] as $fixture) {
            self::assertSame(
                ['ru' => 'Краткое содержание заменено заглушкой.'],
                DescriptionMapper::map(CardFactory::fixture($fixture)),
            );
        }
    }

    public function testLineBreaksAreKeptAndMarkupIsDropped(): void
    {
        $page = CardFactory::fragment([], extra: '<table><tr><td><b><font size=2>Краткое содержание</font></b></td></tr></table>'
            .'<table><tr><td height=1></td></tr></table>'
            ."<table><tr><td><p class='review'>Line <i>one</i><br>line\n two</p><p class='review'>Second</p>"
            ."<p align=right class='review'>&copy; World Art</p></td></tr></table>");

        self::assertSame(['ru' => "Line one\nline two\n\nSecond"], DescriptionMapper::map($page));
    }

    public function testNoBlockOrEmptyBlockIsNull(): void
    {
        self::assertNull(DescriptionMapper::map(CardFactory::fragment(['Тип' => 'ТВ'])));
        self::assertNull(DescriptionMapper::map(WorldartPage::fromBytes('')));
        self::assertNull(DescriptionMapper::map(CardFactory::fragment([], extra: '<table><tr><td><font size=2>Краткое содержание</font></td></tr></table>'
            ."<table><tr><td><p class='review'> </p></td></tr></table>")));
    }
}
