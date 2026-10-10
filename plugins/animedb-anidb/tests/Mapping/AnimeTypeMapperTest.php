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

use AnimeDb\PluginContracts\Model\AnimeType;
use AnimeDb\Plugins\AnimedbAnidb\Mapping\AnimeTypeMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnimeTypeMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?AnimeType}>
     */
    public static function typeProvider(): iterable
    {
        yield 'TV Series' => ['TV Series', AnimeType::Tv];
        yield 'Movie' => ['Movie', AnimeType::Movie];
        yield 'OVA' => ['OVA', AnimeType::Ova];
        yield 'Web' => ['Web', AnimeType::Ona];
        yield 'TV Special' => ['TV Special', AnimeType::Special];
        yield 'Music Video' => ['Music Video', AnimeType::Music];
        yield 'Other' => ['Other', null];
        yield 'Unknown' => ['Unknown', null];
        yield 'unrecognized' => ['Something New', null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
    }

    #[DataProvider('typeProvider')]
    public function testMap(?string $type, ?AnimeType $expected): void
    {
        self::assertSame($expected, AnimeTypeMapper::map($type));
    }

    /**
     * @return iterable<string, array{0: int, 1: AnimeType}>
     */
    public static function fixtureProvider(): iterable
    {
        yield 'OVA' => [2500, AnimeType::Ova];
        yield 'Movie' => [7000, AnimeType::Movie];
        yield 'Music Video' => [13000, AnimeType::Music];
        yield 'Web' => [14500, AnimeType::Ona];
    }

    #[DataProvider('fixtureProvider')]
    public function testLiveFixtureTypes(int $aid, AnimeType $expected): void
    {
        self::assertSame($expected, AnimeTypeMapper::map((string) FixtureCard::load($aid)->type));
    }
}
