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

use AnimeDb\Plugins\AnimedbAnidb\Mapping\CardFieldsMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CardFieldsMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: ?int}>
     */
    public static function episodeCountProvider(): iterable
    {
        yield 'positive' => ['<episodecount>12</episodecount>', 12];
        yield 'zero' => ['<episodecount>0</episodecount>', null];
        yield 'empty' => ['<episodecount></episodecount>', null];
        yield 'garbage' => ['<episodecount>many</episodecount>', null];
        yield 'negative' => ['<episodecount>-3</episodecount>', null];
        yield 'missing' => ['', null];
    }

    #[DataProvider('episodeCountProvider')]
    public function testEpisodeCount(string $fragment, ?int $expected): void
    {
        self::assertSame($expected, CardFieldsMapper::episodeCount(FixtureCard::fromString('<anime>' . $fragment . '</anime>')));
    }

    public function testEpisodeCountFromLiveFixture(): void
    {
        self::assertSame(4, CardFieldsMapper::episodeCount(FixtureCard::load(14500)));
    }

    public function testDurationIsMedianOfRegularEpisodesOnly(): void
    {
        $card = FixtureCard::fromString(
            '<anime><episodes>'
            . '<episode><epno type="1">1</epno><length>20</length></episode>'
            . '<episode><epno type="1">2</epno><length>25</length></episode>'
            . '<episode><epno type="1">3</epno><length>24</length></episode>'
            . '<episode><epno type="2">S1</epno><length>90</length></episode>'
            . '<episode><epno type="2">S2</epno><length>90</length></episode>'
            . '<episode><epno type="3">C1</epno><length>1</length></episode>'
            . '</episodes></anime>',
        );

        self::assertSame(24, CardFieldsMapper::duration($card));
    }

    public function testDurationOfEvenCountIsRoundedMean(): void
    {
        $card = FixtureCard::fromString(
            '<anime><episodes>'
            . '<episode><epno type="1">1</epno><length>5</length></episode>'
            . '<episode><epno type="1">2</epno><length>6</length></episode>'
            . '</episodes></anime>',
        );

        self::assertSame(6, CardFieldsMapper::duration($card));
    }

    public function testDurationFromLiveFixtures(): void
    {
        self::assertSame(25, CardFieldsMapper::duration(FixtureCard::load(7000)));
        self::assertNull(CardFieldsMapper::duration(FixtureCard::fromString('<anime/>')));
        self::assertNull(CardFieldsMapper::duration(FixtureCard::fromString(
            '<anime><episodes><episode><epno type="2">S1</epno><length>30</length></episode></episodes></anime>',
        )));
    }

    public function testStudiosAreOnlyAnimationWork(): void
    {
        self::assertSame(['Shura'], CardFieldsMapper::studios(FixtureCard::load(2500)));
        self::assertSame([], CardFieldsMapper::studios(FixtureCard::load(7000)));
    }

    public function testCoverUsesOnlyTheCardPicture(): void
    {
        $card = FixtureCard::fromString(
            '<anime><characters><character><picture>1.jpg</picture></character></characters>'
            . '<picture>main.jpg</picture></anime>',
        );

        self::assertSame('https://cdn-eu.anidb.net/images/main/main.jpg', CardFieldsMapper::coverUrl($card));
    }

    public function testCoverIsNullWithoutCardPicture(): void
    {
        $card = FixtureCard::fromString('<anime><characters><character><picture>1.jpg</picture></character></characters></anime>');

        self::assertNull(CardFieldsMapper::coverUrl($card));
        self::assertNull(CardFieldsMapper::coverUrl(FixtureCard::fromString('<anime><picture> </picture></anime>')));
        self::assertNull(CardFieldsMapper::coverUrl(FixtureCard::fromString('<anime><picture>../x</picture></anime>')));
    }
}
