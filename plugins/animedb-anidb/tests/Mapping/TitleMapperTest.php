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

use AnimeDb\PluginContracts\Model\AnimeName;
use AnimeDb\PluginContracts\Model\NameRole;
use AnimeDb\Plugins\AnimedbAnidb\Mapping\TitleMapper;
use PHPUnit\Framework\TestCase;

final class TitleMapperTest extends TestCase
{
    public function testMainTitleAndNamesFromLiveFixture(): void
    {
        $card = FixtureCard::load(7000);

        self::assertSame('Bu She Zhi She', TitleMapper::mainTitle($card));
        self::assertEquals([
            new AnimeName('Archer Without a Bow', 'en', NameRole::Synonym),
            new AnimeName('To Shoot Without Shooting', 'en', NameRole::Synonym),
            new AnimeName('Střílet bez střílení', 'cs', NameRole::Synonym),
            new AnimeName('Fusha no Sha', null, NameRole::Synonym),
            new AnimeName('不射之射', 'ja', NameRole::Official),
            new AnimeName('不射之射', 'zh-Hans', NameRole::Official),
        ], TitleMapper::names($card));
    }

    public function testEpisodeTitleIsNotTaken(): void
    {
        $card = FixtureCard::load(7000);
        self::assertStringContainsString('<title xml:lang="en">Complete Movie</title>', (string) $card->asXML());

        $names = array_map(static fn (AnimeName $name): string => $name->name, TitleMapper::names($card));

        self::assertNotContains('Complete Movie', $names);
        self::assertNotSame('Complete Movie', TitleMapper::mainTitle($card));
    }

    public function testLocalesArePassedAsIsAndExtensionsAreNull(): void
    {
        $card = FixtureCard::load(2500);

        self::assertSame('Momiji', TitleMapper::mainTitle($card));
        $locales = [];
        foreach (TitleMapper::names($card) as $name) {
            $locales[$name->name . '/' . $name->role->value] = $name->locale;
        }
        self::assertSame('pt-BR', $locales['Momiji/official'] ?? null);
    }

    public function testRomajiMainTitleHasNoNameEntryAndXPrefixedLocalesAreNull(): void
    {
        $card = FixtureCard::fromString(
            '<anime><titles>'
            . '<title xml:lang="x-jat" type="main">Main</title>'
            . '<title xml:lang="x-jat" type="synonym">Romaji</title>'
            . '<title xml:lang="x-zht" type="official">Pinyin</title>'
            . '<title xml:lang="pt-BR" type="short">Curto</title>'
            . '<title xml:lang="ja" type="kanareading">かな</title>'
            . '<title xml:lang="en">Untyped</title>'
            . '</titles></anime>',
        );

        self::assertSame('Main', TitleMapper::mainTitle($card));
        self::assertEquals([
            new AnimeName('Romaji', null, NameRole::Synonym),
            new AnimeName('Pinyin', null, NameRole::Official),
            new AnimeName('Curto', 'pt-BR', NameRole::Short),
        ], TitleMapper::names($card));
    }

    public function testNoTitles(): void
    {
        $card = FixtureCard::fromString('<anime/>');

        self::assertNull(TitleMapper::mainTitle($card));
        self::assertSame([], TitleMapper::names($card));
    }
}
