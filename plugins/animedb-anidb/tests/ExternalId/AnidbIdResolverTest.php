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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\ExternalId;

use AnimeDb\Plugins\AnimedbAnidb\ExternalId\AnidbIdResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnidbIdResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function recognizedUrls(): iterable
    {
        foreach (['http', 'https'] as $scheme) {
            foreach (['anidb.net', 'www.anidb.net'] as $host) {
                yield "$scheme $host /anime" => ["$scheme://$host/anime/4521"];
                yield "$scheme $host /a" => ["$scheme://$host/a4521"];
                yield "$scheme $host perl-bin" => ["$scheme://$host/perl-bin/animedb.pl?show=anime&aid=4521"];
                yield "$scheme $host perl-bin reordered" => ["$scheme://$host/perl-bin/animedb.pl?aid=4521&show=anime"];
            }
        }
    }

    #[DataProvider('recognizedUrls')]
    public function testRecognizesAllThreeForms(string $url): void
    {
        self::assertSame('4521', AnidbIdResolver::resolve([$url]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ignoredUrls(): iterable
    {
        yield 'other host' => ['https://myanimelist.net/anime/4521'];
        yield 'lookalike host' => ['https://notanidb.net/anime/4521'];
        yield 'subdomain' => ['https://api.anidb.net/anime/4521'];
        yield 'ftp scheme' => ['ftp://anidb.net/anime/4521'];
        yield 'slug suffix' => ['https://anidb.net/anime/4521/relations'];
        yield 'non numeric' => ['https://anidb.net/anime/abc'];
        yield 'character page' => ['https://anidb.net/character/4521'];
        yield 'perl-bin other show' => ['https://anidb.net/perl-bin/animedb.pl?show=character&aid=4521'];
        yield 'perl-bin no aid' => ['https://anidb.net/perl-bin/animedb.pl?show=anime'];
        yield 'perl-bin non numeric aid' => ['https://anidb.net/perl-bin/animedb.pl?show=anime&aid=x'];
        yield 'root' => ['https://anidb.net/'];
        yield 'garbage' => ['not a url'];
    }

    #[DataProvider('ignoredUrls')]
    public function testIgnoresOtherUrls(string $url): void
    {
        self::assertNull(AnidbIdResolver::resolve([$url]));
    }

    public function testReturnsFirstRecognizedUrl(): void
    {
        self::assertSame('7', AnidbIdResolver::resolve([
            'https://example.com/',
            'https://anidb.net/a7',
            'https://anidb.net/a8',
        ]));
    }

    public function testEmptyListGivesNull(): void
    {
        self::assertNull(AnidbIdResolver::resolve([]));
    }
}
