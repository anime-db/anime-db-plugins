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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Mapping;

use AnimeDb\PluginContracts\Model\AnimeType;
use AnimeDb\Plugins\AnimedbShikimori\Mapping\AnimeTypeMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnimeTypeMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?AnimeType}>
     */
    public static function kindProvider(): iterable
    {
        yield 'tv' => ['tv', AnimeType::Tv];
        yield 'movie' => ['movie', AnimeType::Movie];
        yield 'ova' => ['ova', AnimeType::Ova];
        yield 'ona' => ['ona', AnimeType::Ona];
        yield 'special' => ['special', AnimeType::Special];
        yield 'tv_special collapses onto Special' => ['tv_special', AnimeType::Special];
        yield 'music' => ['music', AnimeType::Music];
        yield 'pv has no contract counterpart' => ['pv', null];
        yield 'cm has no contract counterpart' => ['cm', null];
        yield 'unknown kind' => ['something_new', null];
        yield 'null kind' => [null, null];
    }

    #[DataProvider('kindProvider')]
    public function testMap(?string $kind, ?AnimeType $expected): void
    {
        self::assertSame($expected, AnimeTypeMapper::map($kind));
    }
}
