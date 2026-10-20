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

namespace AnimeDb\Plugins\AnimedbWikidata\Tests\ExternalId;

use AnimeDb\Plugins\AnimedbWikidata\WikidataBridge;
use PHPUnit\Framework\Attributes\DataProvider;
use AnimeDb\Plugins\AnimedbWikidata\Tests\Support\WikidataTestCase;

final class WikidataIdResolverTest extends WikidataTestCase
{
    /**
     * @return iterable<string, array{list<string>, string|null}>
     */
    public static function cases(): iterable
    {
        yield 'wikidata wiki' => [['https://www.wikidata.org/wiki/Q699835'], 'Q699835'];
        yield 'wikidata mobile' => [['https://m.wikidata.org/wiki/Q699835'], 'Q699835'];
        yield 'wikidata entity' => [['https://www.wikidata.org/entity/Q699835'], 'Q699835'];
        yield 'imdb' => [['https://www.imdb.com/title/tt1568921/'], 'imdb:tt1568921'];
        yield 'imdb mobile' => [['https://m.imdb.com/title/tt1568921/'], 'imdb:tt1568921'];
        yield 'imdb without www' => [['https://imdb.com/title/tt1568921'], 'imdb:tt1568921'];
        yield 'kinopoisk film' => [['https://www.kinopoisk.ru/film/492366/'], 'kinopoisk:492366'];
        yield 'kinopoisk series' => [['https://www.kinopoisk.ru/series/492366/'], 'kinopoisk:492366'];
        yield 'imdb beats kinopoisk' => [['https://www.kinopoisk.ru/film/492366/', 'https://www.imdb.com/title/tt1568921/'], 'imdb:tt1568921'];
        yield 'wikidata beats the rest, wikidata first' => [['https://www.wikidata.org/wiki/Q699835', 'https://www.imdb.com/title/tt1568921/', 'https://www.kinopoisk.ru/film/492366/'], 'Q699835'];
        yield 'wikidata beats the rest, wikidata last' => [['https://www.kinopoisk.ru/film/492366/', 'https://www.imdb.com/title/tt1568921/', 'https://www.wikidata.org/wiki/Q699835'], 'Q699835'];
        yield 'two imdb links, first wins' => [['https://www.imdb.com/title/tt0000002/', 'https://www.imdb.com/title/tt0000001/'], 'imdb:tt0000002'];
        yield 'two kinopoisk links, first wins' => [['https://www.kinopoisk.ru/film/2/', 'https://www.kinopoisk.ru/series/1/'], 'kinopoisk:2'];
        yield 'two wikidata links, first wins' => [['https://www.wikidata.org/wiki/Q2', 'https://www.wikidata.org/wiki/Q1'], 'Q2'];
        yield 'unrelated link before a recognized one' => [['https://example.com/', 'https://www.imdb.com/title/tt1568921/'], 'imdb:tt1568921'];

        yield 'empty list' => [[], null];
        yield 'unrelated links' => [['https://example.com/film/1', 'https://www.imdb.com/name/nm0000001/', 'https://www.kinopoisk.ru/name/1/', 'https://www.wikidata.org/wiki/Property:P345'], null];
        yield 'not http' => [['ftp://www.imdb.com/title/tt1568921/'], null];

        yield 'mal' => [['https://myanimelist.net/anime/7711', 'https://www.imdb.com/title/tt1568921/'], null];
        yield 'anidb' => [['https://anidb.net/anime/7258', 'https://www.kinopoisk.ru/film/492366/'], null];
        yield 'shikimori io' => [['https://shikimori.io/animes/7711', 'https://www.kinopoisk.ru/film/492366/'], null];
        yield 'shikimori one subdomain' => [['https://www.kinopoisk.ru/film/492366/', 'https://ru.shikimori.one/animes/7711'], null];
        yield 'shikimori org' => [['https://shikimori.org/animes/7711'], null];
        yield 'world-art' => [['https://www.world-art.ru/animation/animation.php?id=1', 'https://www.imdb.com/title/tt1568921/'], null];
        yield 'ann' => [['https://www.animenewsnetwork.com/encyclopedia/anime.php?id=11212', 'https://www.imdb.com/title/tt1568921/'], null];
        yield 'anilist' => [['https://anilist.co/anime/7711', 'https://www.imdb.com/title/tt1568921/'], null];
        yield 'anime link together with wikidata, wikidata first' => [['https://www.wikidata.org/wiki/Q699835', 'https://myanimelist.net/anime/7711'], null];
        yield 'anime link together with wikidata, wikidata last' => [['https://myanimelist.net/anime/7711', 'https://www.wikidata.org/wiki/Q699835'], null];
        yield 'anime host with www' => [['https://www.myanimelist.net/anime/7711', 'https://www.imdb.com/title/tt1568921/'], null];
        yield 'host that only ends like an anime host' => [['https://notmyanimelist.net/', 'https://www.imdb.com/title/tt1568921/'], 'imdb:tt1568921'];
    }

    /**
     * @param list<string> $urls
     */
    #[DataProvider('cases')]
    public function testResolve(array $urls, ?string $expected): void
    {
        self::assertSame($expected, (new WikidataBridge($this->client))->resolveExternalId($urls));
    }

    public function testFindIsAlwaysEmpty(): void
    {
        $bridge = new WikidataBridge($this->client);

        self::assertSame([], $bridge->find('Arrietty'));
        self::assertSame([], $bridge->find(''));
        self::assertSame([], $bridge->find('借りぐらしのアリエッティ', static fn () => null));
    }
}
