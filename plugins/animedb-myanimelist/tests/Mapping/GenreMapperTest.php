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

use AnimeDb\PluginContracts\Model\Demographic;
use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\ThemeCode;
use AnimeDb\Plugins\AnimedbMyanimelist\Mapping\GenreMapper;
use PHPUnit\Framework\TestCase;

final class GenreMapperTest extends TestCase
{
    /**
     * card_3455 fixture (issue #177) is a live MyAnimeList response whose flat `genres[]`
     * mixes a contract GenreCode (Comedy, Romance, Sci-Fi), two ThemeCode entries (Harem,
     * School), a Demographic (Shounen) and one 18+ genre (Ecchi) that has no contract
     * counterpart at all. It is the fixture the issue calls out for this exact case.
     */
    public function testEcchiIsDroppedWhileTheRestOfTheFixtureIsRoutedByAxis(): void
    {
        $fixture = self::loadFixture('card_3455.json');

        $result = GenreMapper::map($fixture['genres']);

        self::assertSame(
            [GenreCode::Comedy, GenreCode::Romance, GenreCode::SciFi],
            $result['genres'],
        );
        self::assertSame([ThemeCode::Harem, ThemeCode::School], $result['themes']);
        self::assertSame([Demographic::Shounen], $result['demographics']);
    }

    public function testAllDemographicNamesRouteToDemographic(): void
    {
        $names = ['Shounen', 'Shoujo', 'Seinen', 'Josei', 'Kids'];

        $result = GenreMapper::map(self::genreList($names));

        self::assertCount(\count($names), $result['demographics']);
        self::assertSame([], $result['genres']);
        self::assertSame([], $result['themes']);
    }

    public function testKnownGenreNameRoutesToGenreCode(): void
    {
        $result = GenreMapper::map(self::genreList(['Action', 'Sci-Fi']));

        self::assertSame([GenreCode::Action, GenreCode::SciFi], $result['genres']);
        self::assertSame([], $result['themes']);
        self::assertSame([], $result['demographics']);
    }

    public function testKnownThemeNameRoutesToThemeCode(): void
    {
        $result = GenreMapper::map(self::genreList(['Urban Fantasy', 'Gore']));

        self::assertSame([ThemeCode::UrbanFantasy, ThemeCode::Gore], $result['themes']);
        self::assertSame([], $result['genres']);
        self::assertSame([], $result['demographics']);
    }

    /**
     * 18+ MyAnimeList genres have no contract counterpart and must not fail the lookup.
     */
    public function test18PlusGenresAreDroppedByDesign(): void
    {
        $result = GenreMapper::map(self::genreList(['Hentai', 'Erotica', 'Ecchi']));

        self::assertSame(['genres' => [], 'themes' => [], 'demographics' => []], $result);
    }

    public function testUnknownGenreNameIsDroppedNotFatal(): void
    {
        $result = GenreMapper::map(self::genreList(['Some Future MyAnimeList Genre']));

        self::assertSame(['genres' => [], 'themes' => [], 'demographics' => []], $result);
    }

    public function testEntriesWithoutAUsableNameAreIgnored(): void
    {
        $result = GenreMapper::map([['name' => null], ['name' => ''], [], ['id' => 5]]);

        self::assertSame(['genres' => [], 'themes' => [], 'demographics' => []], $result);
    }

    /**
     * @param list<string> $names
     *
     * @return list<array{name: string}>
     */
    private static function genreList(array $names): array
    {
        return array_map(static fn (string $name): array => ['name' => $name], $names);
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
