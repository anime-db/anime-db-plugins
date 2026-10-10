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

use AnimeDb\PluginContracts\Model\Demographic;
use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\ThemeCode;
use AnimeDb\Plugins\AnimedbAnidb\Mapping\TagMapper;
use PHPUnit\Framework\TestCase;

final class TagMapperTest extends TestCase
{
    private static function tag(string $name, string $attributes = 'weight="300" localspoiler="false" globalspoiler="false"'): string
    {
        return \sprintf('<tag id="1" %s><name>%s</name></tag>', $attributes, $name);
    }

    public function testAxesSynonymsAndDrops(): void
    {
        $card = FixtureCard::fromString(
            '<anime><tags>'
            . self::tag('romance')
            . self::tag('Science Fiction')
            . self::tag('yuri')
            . self::tag('school life')
            . self::tag('mecha')
            . self::tag('Seinen')
            . self::tag('romance')
            . self::tag('no such tag')
            . self::tag('')
            . '</tags></anime>',
        );

        self::assertSame([
            'genres' => [GenreCode::Romance, GenreCode::SciFi, GenreCode::GirlsLove],
            'themes' => [ThemeCode::School, ThemeCode::Mecha],
            'demographics' => [Demographic::Seinen],
        ], TagMapper::map($card));
    }

    public function testSpoilerTagsAreDropped(): void
    {
        $card = FixtureCard::fromString(
            '<anime><tags>'
            . self::tag('drama', 'weight="300" localspoiler="true" globalspoiler="false"')
            . self::tag('horror', 'weight="300" localspoiler="false" globalspoiler="true"')
            . self::tag('fantasy')
            . '</tags></anime>',
        );

        self::assertSame(
            ['genres' => [GenreCode::Fantasy], 'themes' => [], 'demographics' => []],
            TagMapper::map($card),
        );
    }

    public function testZeroWeightIsIgnored(): void
    {
        $card = FixtureCard::fromString(
            '<anime><tags>'
            . self::tag('comedy', 'weight="0" localspoiler="false" globalspoiler="false"')
            . self::tag('themes', 'weight="0" localspoiler="false" globalspoiler="false"')
            . '</tags></anime>',
        );

        self::assertSame(
            ['genres' => [GenreCode::Comedy], 'themes' => [], 'demographics' => []],
            TagMapper::map($card),
        );
    }

    public function testLiveFixtureKeepsImplicitlyAppliedParents(): void
    {
        $result = TagMapper::map(FixtureCard::load(2500));

        // `romance` and `school life` carry weight="0" (implicit parents of applied tags) and are kept.
        self::assertContains(GenreCode::Romance, $result['genres']);
        self::assertContains(GenreCode::GirlsLove, $result['genres']);
        self::assertSame([ThemeCode::School], $result['themes']);
        self::assertSame([], $result['demographics']);
    }

    public function testCardWithoutTags(): void
    {
        self::assertSame(
            ['genres' => [], 'themes' => [], 'demographics' => []],
            TagMapper::map(FixtureCard::load(13000)),
        );
    }
}
