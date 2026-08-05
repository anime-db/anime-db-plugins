<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Mapping;

use AnimeDb\PluginContracts\Model\Demographic;
use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\ThemeCode;
use AnimeDb\Plugins\AnimedbShikimori\Mapping\GenreMapper;
use PHPUnit\Framework\TestCase;

/**
 * Covers the full Shikimori `genres(entryType: Anime)` axis (80 names, snapshotted in issue #30),
 * not just the one cross-axis case, per the issue's explicit test-coverage requirement.
 */
final class GenreMapperTest extends TestCase
{
    private const DEMOGRAPHIC_NAMES = ['Shounen', 'Shoujo', 'Seinen', 'Josei', 'Kids'];

    /**
     * Shikimori `kind: genre` names that ARE covered by the contract's GenreCode.
     */
    private const MAPPED_GENRE_NAMES = [
        'Action', 'Adventure', 'Avant Garde', 'Boys Love', 'Comedy', 'Drama', 'Fantasy',
        'Girls Love', 'Gourmet', 'Horror', 'Mystery', 'Romance', 'Sci-Fi', 'Slice of Life',
        'Sports', 'Supernatural', 'Suspense',
    ];

    /**
     * Shikimori `kind: genre` names that are 18+ and intentionally absent from the contract.
     */
    private const DROPPED_18_PLUS_GENRE_NAMES = ['Ecchi', 'Erotica', 'Hentai', 'Yaoi', 'Yuri'];

    /**
     * Shikimori `kind: theme` names that ARE covered by the contract's ThemeCode. "Award
     * Winning" is deliberately excluded here — it is the one cross-axis case, see
     * {@see self::testAwardWinningIsTheOnlyThemeRoutedToGenreCode()}.
     */
    private const MAPPED_THEME_NAMES = [
        'Adult Cast', 'Anthropomorphic', 'CGDCT', 'Childcare', 'Combat Sports', 'Crossdressing',
        'Delinquents', 'Detective', 'Educational', 'Gag Humor', 'Gore', 'Harem',
        'High Stakes Game', 'Historical', 'Idols (Female)', 'Idols (Male)', 'Isekai',
        'Iyashikei', 'Love Polygon', 'Love Status Quo', 'Magical Sex Shift', 'Mahou Shoujo',
        'Martial Arts', 'Mecha', 'Medical', 'Military', 'Music', 'Mythology',
        'Organized Crime', 'Otaku Culture', 'Parody', 'Performing Arts', 'Pets', 'Psychological',
        'Racing', 'Reincarnation', 'Reverse Harem', 'Samurai', 'School', 'Showbiz', 'Space',
        'Strategy Game', 'Super Power', 'Survival', 'Team Sports', 'Time Travel',
        'Urban Fantasy', 'Vampire', 'Video Game', 'Villainess', 'Visual Arts', 'Workplace',
    ];

    public function testAllDemographicNamesRouteToDemographic(): void
    {
        $result = GenreMapper::map(self::genreList(self::DEMOGRAPHIC_NAMES));

        self::assertCount(\count(self::DEMOGRAPHIC_NAMES), $result['demographics']);
        self::assertSame([], $result['genres']);
        self::assertSame([], $result['themes']);
        foreach ($result['demographics'] as $demographic) {
            self::assertInstanceOf(Demographic::class, $demographic);
        }
    }

    public function testAllMappedGenreNamesRouteToGenreCode(): void
    {
        $result = GenreMapper::map(self::genreList(self::MAPPED_GENRE_NAMES));

        self::assertCount(\count(self::MAPPED_GENRE_NAMES), $result['genres']);
        self::assertSame([], $result['themes']);
        self::assertSame([], $result['demographics']);
    }

    public function testAllMappedThemeNamesRouteToThemeCode(): void
    {
        $result = GenreMapper::map(self::genreList(self::MAPPED_THEME_NAMES));

        self::assertCount(\count(self::MAPPED_THEME_NAMES), $result['themes']);
        self::assertSame([], $result['genres']);
        self::assertSame([], $result['demographics']);
    }

    public function test18PlusGenresAreDroppedByDesign(): void
    {
        $result = GenreMapper::map(self::genreList(self::DROPPED_18_PLUS_GENRE_NAMES));

        self::assertSame(['genres' => [], 'themes' => [], 'demographics' => []], $result);
    }

    /**
     * The single cross-axis case: Shikimori tags "Award Winning" as `kind: theme`, but the
     * contract's GenreCode (not ThemeCode) owns `award-winning`, because the contract axis
     * follows MAL rather than Shikimori. GenreMapper must place it in `genres` regardless of
     * what Shikimori's own `kind` field says.
     */
    public function testAwardWinningIsTheOnlyThemeRoutedToGenreCode(): void
    {
        $result = GenreMapper::map([['name' => 'Award Winning', 'kind' => 'theme']]);

        self::assertSame([GenreCode::AwardWinning], $result['genres']);
        self::assertSame([], $result['themes']);
    }

    public function testSlugifiesParenthesesAndSpaces(): void
    {
        $result = GenreMapper::map([['name' => 'Idols (Female)']]);

        self::assertSame([ThemeCode::IdolsFemale], $result['themes']);
    }

    public function testUnknownGenreNameIsDroppedNotFatal(): void
    {
        $result = GenreMapper::map([['name' => 'Some Future Shikimori Genre']]);

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
}
