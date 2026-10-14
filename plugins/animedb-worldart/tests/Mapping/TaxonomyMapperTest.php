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

use AnimeDb\PluginContracts\Model\Demographic;
use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\ThemeCode;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\AudienceTable;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\GenreTable;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\TaxonomyMapper;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use AnimeDb\Plugins\AnimedbWorldart\Tests\Support\CardFactory;
use PHPUnit\Framework\TestCase;

final class TaxonomyMapperTest extends TestCase
{
    private const LIST = 'http://www.world-art.ru/animation/list.php?';

    private static function genreLinks(int ...$ids): string
    {
        return implode(', ', array_map(static fn (int $id) => "<a href='".self::LIST."public_genre=$id'>x</a>", $ids));
    }

    private static function tag(int $id): string
    {
        return "<div class='newtag'><a href='".self::LIST."public_keyword=$id&amp;sort=10'>x</a> <font size=1>1.0</font></div>";
    }

    public function testGenresAndTagsOfFrieren(): void
    {
        $result = TaxonomyMapper::genresAndThemes(CardFactory::fixture('card_11466.html'));

        // `743` (classic fantasy) maps to Fantasy again: given once
        self::assertSame([GenreCode::Adventure, GenreCode::Fantasy], $result['genres']);
        self::assertSame([ThemeCode::AdultCast, ThemeCode::SuperPower, ThemeCode::Survival], $result['themes']);
    }

    public function testGenresAndTagsOfAkira(): void
    {
        $result = TaxonomyMapper::genresAndThemes(CardFactory::fixture('card_1.html'));

        // `628` (cyberpunk) is SciFi again; `851` (nudity) and `719` (cyborgs) give nothing
        self::assertSame([GenreCode::Adventure, GenreCode::SciFi, GenreCode::Supernatural], $result['genres']);
        self::assertSame([ThemeCode::SuperPower, ThemeCode::Gore, ThemeCode::Delinquents, ThemeCode::Military], $result['themes']);
    }

    public function testIdsWithoutRowAndUnknownIdsAreSkipped(): void
    {
        $page = CardFactory::fragment(
            ['Жанр' => self::genreLinks(36, 41, 5, 99, 7, 5)],
            extra: self::tag(851).self::tag(676).self::tag(999999).self::tag(698),
        );

        $result = TaxonomyMapper::genresAndThemes($page);

        self::assertSame([GenreCode::Mystery, GenreCode::Drama], $result['genres']);
        self::assertSame([ThemeCode::SuperPower], $result['themes']);
    }

    public function testExcludedIdsHaveNoRows(): void
    {
        foreach ([36, 41, 851] as $id) {
            self::assertArrayNotHasKey($id, GenreTable::MAP);
        }
    }

    public function testNameIsNotUsedOnlyTheId(): void
    {
        // the text says "комедия", the id is unknown: nothing
        $page = CardFactory::fragment(['Жанр' => "<a href='".self::LIST."public_genre=9999'>комедия</a>"]);

        self::assertSame(['genres' => [], 'themes' => []], TaxonomyMapper::genresAndThemes($page));
    }

    public function testAudienceIdIsNotLookedUpInGenreTable(): void
    {
        // `2` is martial arts among the genres and seinen among the audiences
        self::assertSame(ThemeCode::MartialArts, GenreTable::MAP[2]);

        // a neutral link text: the result can only come from the id
        $page = CardFactory::fragment(
            [
                'Целевая аудитория' => "<a href='".self::LIST."public_target_audience=2'>x</a>",
                'Жанр' => self::genreLinks(2),
            ],
            extra: self::tag(2),
        );

        self::assertSame(Demographic::Seinen, TaxonomyMapper::demographic($page));
        self::assertSame(['genres' => [], 'themes' => [ThemeCode::MartialArts]], TaxonomyMapper::genresAndThemes($page));
    }

    public function testAudienceOfFixtures(): void
    {
        self::assertSame(Demographic::Seinen, TaxonomyMapper::demographic(CardFactory::fixture('card_1.html')));
        self::assertSame(Demographic::Seinen, TaxonomyMapper::demographic(CardFactory::fixture('card_6.html')));
        self::assertNull(TaxonomyMapper::demographic(CardFactory::fixture('card_11466.html')));
    }

    public function testAllAudienceIdsAndNames(): void
    {
        foreach (AudienceTable::BY_ID as $id => $demographic) {
            $page = CardFactory::fragment(['Целевая аудитория' => "<a href='".self::LIST."public_target_audience=$id'>x</a>"]);
            self::assertSame($demographic, TaxonomyMapper::demographic($page));
        }

        // a value without a link: by the name
        self::assertSame(Demographic::Josei, TaxonomyMapper::demographic(CardFactory::fragment(['Целевая аудитория' => ' Дзёсэй '])));
        self::assertNull(TaxonomyMapper::demographic(CardFactory::fragment(['Целевая аудитория' => 'неизвестно'])));
        self::assertNull(TaxonomyMapper::demographic(CardFactory::fragment(['Целевая аудитория' => "<a href='".self::LIST."public_target_audience=77'>x</a>"])));
    }

    public function testEmptyPage(): void
    {
        $page = WorldartPage::fromBytes('');

        self::assertSame(['genres' => [], 'themes' => []], TaxonomyMapper::genresAndThemes($page));
        self::assertNull(TaxonomyMapper::demographic($page));
    }
}
