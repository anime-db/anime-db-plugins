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

namespace AnimeDb\Plugins\AnimedbWorldart\Tests\Search;

use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use AnimeDb\Plugins\AnimedbWorldart\Search\SearchPageParser;
use PHPUnit\Framework\TestCase;

final class SearchPageParserTest extends TestCase
{
    public function testNarutoPage(): void
    {
        $results = SearchPageParser::parse(
            WorldartPage::fromBytes((string) file_get_contents(__DIR__.'/../Fixture/search_naruto.html')),
        );

        self::assertCount(24, $results);
        self::assertSame('11822', $results[0]->id);
        self::assertSame('Наруто', $results[0]->name);
        self::assertSame(2027, $results[0]->year);
        self::assertSame(['Naruto'], $results[0]->otherNames);
        self::assertSame('262', $results[23]->id);
        self::assertSame('Наруто [ТВ-1]', $results[23]->name);
        self::assertSame(2002, $results[23]->year);
        self::assertSame(['Naruto', 'ナルト'], $results[23]->otherNames);
    }

    public function testNameWithParenthesesKeepsThemWithoutTheTail(): void
    {
        $byId = [];
        foreach (SearchPageParser::parse(
            WorldartPage::fromBytes((string) file_get_contents(__DIR__.'/../Fixture/search_naruto.html')),
        ) as $result) {
            $byId[$result->id] = $result;
        }

        self::assertSame('Наруто (фильм девятый)', $byId['837']->name);
        self::assertSame([], $byId['8152']->otherNames);
        self::assertSame('Naruto x UT', $byId['8152']->name);
    }

    public function testOneResultPerId(): void
    {
        $html = "<table><tr><td><a href='animation/animation.php?id=5'>A&nbsp;(2001, Япония)</a><br>a<br></td></tr>"
            ."<tr><td><a href='animation/animation.php?id=5'>A&nbsp;(2001, Япония)</a><br>a<br></td></tr></table>";

        self::assertCount(1, SearchPageParser::parse(WorldartPage::fromBytes($html)));
    }
}
