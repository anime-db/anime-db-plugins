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

namespace AnimeDb\Plugins\AnimedbWorldart\Tests\ExternalId;

use AnimeDb\Plugins\AnimedbWorldart\ExternalId\WorldartIdResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorldartIdResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function urls(): iterable
    {
        yield 'www http' => ['http://www.world-art.ru/animation/animation.php?id=262', '262'];
        yield 'without www' => ['http://world-art.ru/animation/animation.php?id=262', '262'];
        yield 'https' => ['https://www.world-art.ru/animation/animation.php?id=6', '6'];
        yield 'https without www' => ['https://world-art.ru/animation/animation.php?id=6', '6'];
        yield 'upper-case host' => ['http://WWW.World-Art.ru/animation/animation.php?id=6', '6'];
        yield 'extra parameter' => ['http://www.world-art.ru/animation/animation.php?x=1&id=6', '6'];
        yield 'full cast page' => ['http://www.world-art.ru/animation/animation_full_cast.php?id=6', null];
        yield 'cinema' => ['http://www.world-art.ru/cinema/cinema.php?id=6', null];
        yield 'zero' => ['http://www.world-art.ru/animation/animation.php?id=0', null];
        yield 'negative' => ['http://www.world-art.ru/animation/animation.php?id=-1', null];
        yield 'not a number' => ['http://www.world-art.ru/animation/animation.php?id=abc', null];
        yield 'leading zero' => ['http://www.world-art.ru/animation/animation.php?id=06', null];
        yield 'no id' => ['http://www.world-art.ru/animation/animation.php', null];
        yield 'other host' => ['http://example.com/animation/animation.php?id=6', null];
        yield 'look-alike host' => ['http://world-art.ru.example.com/animation/animation.php?id=6', null];
        yield 'other scheme' => ['ftp://www.world-art.ru/animation/animation.php?id=6', null];
        yield 'garbage' => ['not a url', null];
    }

    #[DataProvider('urls')]
    public function testResolve(string $url, ?string $expected): void
    {
        self::assertSame($expected, WorldartIdResolver::resolve([$url]));
    }

    public function testFirstRecognizedUrlWins(): void
    {
        self::assertSame('7', WorldartIdResolver::resolve([
            'http://example.com/',
            'http://www.world-art.ru/animation/animation.php?id=7',
            'http://www.world-art.ru/animation/animation.php?id=8',
        ]));
        self::assertNull(WorldartIdResolver::resolve([]));
    }
}
