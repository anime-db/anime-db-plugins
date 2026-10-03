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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Tests\ExternalId;

use AnimeDb\Plugins\AnimedbMyanimelist\ExternalId\MalIdResolver;
use PHPUnit\Framework\TestCase;

final class MalIdResolverTest extends TestCase
{
    public function testResolvesIdFromPlainAnimeUrl(): void
    {
        self::assertSame('20', MalIdResolver::resolve(['https://myanimelist.net/anime/20']));
    }

    public function testResolvesIdFromAnimeUrlWithSlug(): void
    {
        self::assertSame('20', MalIdResolver::resolve(['https://myanimelist.net/anime/20/Naruto']));
    }

    public function testResolvesIdFromLegacyQueryStringUrl(): void
    {
        self::assertSame('20', MalIdResolver::resolve(['https://myanimelist.net/anime.php?id=20']));
    }

    public function testResolvesIdFromASubdomain(): void
    {
        self::assertSame('20', MalIdResolver::resolve(['https://cdn.myanimelist.net/anime/20/Naruto']));
    }

    public function testReturnsNullForAMangaUrl(): void
    {
        self::assertNull(MalIdResolver::resolve(['https://myanimelist.net/manga/20']));
    }

    public function testReturnsNullWhenNoUrlMatchesMyAnimeListDomain(): void
    {
        self::assertNull(MalIdResolver::resolve(['https://shikimori.io/animes/20-naruto']));
    }

    public function testReturnsNullForAnEmptyUrlList(): void
    {
        self::assertNull(MalIdResolver::resolve([]));
    }

    public function testMatchesTheFirstMyAnimeListUrlAmongSeveral(): void
    {
        self::assertSame('42', MalIdResolver::resolve([
            'https://shikimori.io/animes/20-naruto',
            'https://myanimelist.net/anime/42/Cowboy_Bebop',
        ]));
    }
}
