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

use AnimeDb\Plugins\AnimedbWorldart\Mapping\ExternalUrlsMapper;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use AnimeDb\Plugins\AnimedbWorldart\Tests\Support\CardFactory;
use PHPUnit\Framework\TestCase;

final class ExternalUrlsMapperTest extends TestCase
{
    public function testEntityEncodedLinksOfNewCard(): void
    {
        $urls = ExternalUrlsMapper::map(CardFactory::fixture('card_11466.html'), 11466);

        self::assertSame('http://www.world-art.ru/animation/animation.php?id=11466', $urls[0]);
        self::assertSame([
            'http://www.world-art.ru/animation/animation.php?id=11466',
            'https://www.animenewsnetwork.com/encyclopedia/anime.php?id=26334',
            'https://anidb.net/anime/17617',
            'https://myanimelist.net/anime/52991',
            'https://cal.syoboi.jp/tid/6776/time',
            'https://www.allcinema.net/cinema/386233',
            'https://anilist.co/anime/154587/Sousou-no-Frieren/',
            'https://www.kinopoisk.ru/series/5401195/',
        ], \array_slice($urls, 0, 8));

        $wikipedias = array_filter($urls, static fn (string $url) => str_contains($url, 'wikipedia.org'));
        self::assertCount(3, $wikipedias);
        self::assertContains('https://en.wikipedia.org/wiki/Frieren_(TV_series)', $urls);

        foreach ($urls as $url) {
            self::assertStringNotContainsString('&#', $url);
            foreach (['crunchyroll', 'natalie', 'fansubs', 'seesaa'] as $denied) {
                self::assertStringNotContainsString($denied, $url);
            }
        }
    }

    public function testPlainTextLinksOfOldCardAreTakenLikeEncodedOnes(): void
    {
        $urls = ExternalUrlsMapper::map(CardFactory::fixture('card_1.html'), 1);

        self::assertSame('http://www.world-art.ru/animation/animation.php?id=1', $urls[0]);
        self::assertContains('https://www.animenewsnetwork.com/encyclopedia/anime.php?id=375', $urls);
        self::assertContains('http://anidb.net/perl-bin/animedb.pl?show=anime&aid=28', $urls);
        self::assertContains('http://myanimelist.net/anime/47/', $urls);
        self::assertContains('http://www.allcinema.net/prog/show_c.php?num_c=89993', $urls);
        self::assertContains('https://www.kinopoisk.ru/film/8224/', $urls);
        self::assertContains('http://ja.wikipedia.org/wiki/AKIRA_%28%E6%BC%AB%E7%94%BB%29', $urls);
        self::assertContains('https://en.wikipedia.org/wiki/Akira_(1988_film)', $urls);
        self::assertNotContains('http://www.fansubs.ru/base.php?id=16', $urls);
    }

    public function testHostRules(): void
    {
        $links = ['https://shikimori.one/animes/1', 'http://shikimori.me/a', 'https://www.shikimori.io/a', 'https://shikimori.example.com/a',
            'https://evilanidb.net/a', 'https://anidb.net.evil.org/a', 'https://wikipedia.org/wiki/A', 'https://notwikipedia.org/a',
            'https://syoboi.jp/a', 'https://cal.syoboi.jp/a', 'https://allcinema.net/a', 'https://www.youtube.com/a',
            'ftp://anidb.net/a', 'anidb.net/a', 'https://anidb.net/a b', 'javascript:alert(1)', 'https://zh.wikipedia.org/wiki/B',
            'https://evil.com\\@anidb.net/a', 'https://user@anidb.net/a',
        ];
        $html = '';
        foreach ($links as $link) {
            $html .= "<noindex><a href='".$link."'>x</a></noindex>";
        }

        $urls = ExternalUrlsMapper::map(CardFactory::fragment([], extra: $html), 5);

        self::assertSame([
            'http://www.world-art.ru/animation/animation.php?id=5',
            'https://shikimori.one/animes/1',
            'http://shikimori.me/a',
            'https://www.shikimori.io/a',
            'https://cal.syoboi.jp/a',
            'https://allcinema.net/a',
            'https://zh.wikipedia.org/wiki/B',
        ], $urls);
    }

    public function testDuplicatesAreRemoved(): void
    {
        $html = "<noindex><a href='https://anidb.net/anime/1'>a</a></noindex><noindex><a href='https://anidb.net/anime/1'>b</a></noindex>";

        self::assertCount(2, ExternalUrlsMapper::map(CardFactory::fragment([], extra: $html), 5));
    }

    public function testNoLinksGivesOnlyOwnPage(): void
    {
        self::assertSame(
            ['http://www.world-art.ru/animation/animation.php?id=7'],
            ExternalUrlsMapper::map(WorldartPage::fromBytes(''), 7),
        );
    }
}
