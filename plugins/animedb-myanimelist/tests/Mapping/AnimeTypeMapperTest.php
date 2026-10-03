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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Tests\Mapping;

use AnimeDb\PluginContracts\Model\AnimeType;
use AnimeDb\Plugins\AnimedbMyanimelist\Mapping\AnimeTypeMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnimeTypeMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?AnimeType}>
     */
    public static function mediaTypeProvider(): iterable
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
        yield 'unknown has no contract counterpart' => ['unknown', null];
        yield 'unrecognized media_type' => ['something_new', null];
        yield 'null media_type' => [null, null];
    }

    #[DataProvider('mediaTypeProvider')]
    public function testMap(?string $mediaType, ?AnimeType $expected): void
    {
        self::assertSame($expected, AnimeTypeMapper::map($mediaType));
    }

    /**
     * card_63143 fixture (issue #177) carries `media_type: "unknown"` for an unaired sequel —
     * a live confirmation that the value really occurs, not just a hypothetical.
     */
    public function testUnknownFromFixtureMapsToNull(): void
    {
        $fixture = self::loadFixture('card_63143.json');

        self::assertSame('unknown', $fixture['media_type']);
        self::assertNull(AnimeTypeMapper::map($fixture['media_type']));
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadFixture(string $name): array
    {
        $path = \dirname(__DIR__).'/Fixture/'.$name;

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
