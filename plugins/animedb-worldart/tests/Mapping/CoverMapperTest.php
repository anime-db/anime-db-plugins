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

use AnimeDb\Plugins\AnimedbWorldart\Mapping\CoverMapper;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use AnimeDb\Plugins\AnimedbWorldart\Tests\Support\CardFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoverMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function cards(): iterable
    {
        yield 'card_1.html' => ['card_1.html', 1];
        yield 'card_6.html' => ['card_6.html', 6];
        yield 'card_1500.html' => ['card_1500.html', 1500];
        yield 'card_10000.html' => ['card_10000.html', 10000];
        yield 'card_11466.html' => ['card_11466.html', 11466];
        yield 'card_movie_20.html' => ['card_movie_20.html', 20];
        yield 'card_ova_100.html' => ['card_ova_100.html', 100];
        yield 'card_tv_special_8000.html' => ['card_tv_special_8000.html', 8000];
        yield 'card_web_11000.html' => ['card_web_11000.html', 11000];
    }

    #[DataProvider('cards')]
    public function testCoverIsTheImgSrcAndTheFallbackAgrees(string $fixture, int $id): void
    {
        $page = CardFactory::fixture($fixture);
        $cover = CoverMapper::map($page, $id);

        self::assertSame($page->coverSrc(), $cover);
        self::assertSame($cover, CoverMapper::fallback($id));
    }

    public function testIdDivisibleByThousand(): void
    {
        self::assertSame('http://www.world-art.ru/animation/img/11000/10000/1.jpg', CoverMapper::fallback(10000));
        self::assertSame('http://www.world-art.ru/animation/img/11000/10000/1.jpg', CoverMapper::map(CardFactory::fixture('card_10000.html'), 10000));
    }

    public function testRelativeSrcIsCompleted(): void
    {
        $page = CardFactory::fragment([], extra: "<img src='/animation/img/3000/2500/1.jpg'>");
        self::assertSame('http://www.world-art.ru/animation/img/3000/2500/1.jpg', CoverMapper::map($page, 2500));

        $page = CardFactory::fragment([], extra: "<img src='animation/img/3000/2500/1.jpg'>");
        self::assertSame('http://www.world-art.ru/animation/img/3000/2500/1.jpg', CoverMapper::map($page, 2500));
    }

    public function testSrcWinsOverTheFormula(): void
    {
        $page = CardFactory::fragment([], extra: "<img src='http://www.world-art.ru/animation/img/5000/4500/1.jpg'>");

        self::assertSame('http://www.world-art.ru/animation/img/5000/4500/1.jpg', CoverMapper::map($page, 12));
    }

    public function testNoImgFallsBackToTheFormula(): void
    {
        self::assertSame('http://www.world-art.ru/animation/img/1000/12/1.jpg', CoverMapper::map(CardFactory::fragment([]), 12));
        self::assertSame('http://www.world-art.ru/animation/img/1000/12/1.jpg', CoverMapper::map(WorldartPage::fromBytes(''), 12));
        self::assertNull(CoverMapper::map(WorldartPage::fromBytes(''), 0));
    }

    public function testFrameImagesAreNotTheCover(): void
    {
        $page = CardFactory::fragment([], extra: "<img src='http://www.world-art.ru/animation/img/converted_images_1000/optimize_b/1-4.jpg'>");

        self::assertSame('http://www.world-art.ru/animation/img/1000/12/1.jpg', CoverMapper::map($page, 12));
    }
}
