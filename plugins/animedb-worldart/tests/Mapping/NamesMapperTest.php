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

namespace AnimeDb\Plugins\AnimedbWorldart\Tests\Mapping;

use AnimeDb\PluginContracts\Model\NameRole;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\NamesMapper;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use AnimeDb\Plugins\AnimedbWorldart\Tests\Support\CardFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NamesMapperTest extends TestCase
{
    public function testTitleKeepsTheSeasonMark(): void
    {
        self::assertSame('Фрирен [ТВ-1]', NamesMapper::title(CardFactory::fixture('card_11466.html')));
        self::assertSame('Акира', NamesMapper::title(CardFactory::fixture('card_1.html')));
    }

    public function testTitleIsTrimmed(): void
    {
        self::assertSame('Название', NamesMapper::title(CardFactory::fragment([], "  \n Название \n ")));
    }

    public function testAlternativeNamesOfFullCard(): void
    {
        $names = NamesMapper::alternativeNames(CardFactory::fixture('card_11466.html'));

        self::assertNotNull($names);
        self::assertCount(3, $names);
        self::assertSame(["Frieren: Beyond Journey's End", 'en', NameRole::Official], [$names[0]->name, $names[0]->locale, $names[0]->role]);
        self::assertSame(['Sousou no Frieren', null, NameRole::Official], [$names[1]->name, $names[1]->locale, $names[1]->role]);
        self::assertSame(['葬送のフリーレン', 'ja', NameRole::Official], [$names[2]->name, $names[2]->locale, $names[2]->role]);
    }

    #[DataProvider('cards')]
    public function testInvariantsOnEveryCard(string $fixture): void
    {
        $page = CardFactory::fixture($fixture);

        foreach (NamesMapper::alternativeNames($page) ?? [] as $name) {
            self::assertNotSame('', $name->name);
            self::assertNotSame($page->title(), $name->name);
            self::assertNotSame('ja-Latn', $name->locale);
            self::assertContains($name->locale, [null, 'en', 'ja']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cards(): iterable
    {
        foreach (CardFactory::cards() as $card) {
            yield $card => [$card];
        }
    }

    public function testRomajiOnlyCard(): void
    {
        $names = NamesMapper::alternativeNames(CardFactory::fixture('card_1.html'));

        self::assertNotNull($names);
        self::assertCount(1, $names);
        self::assertNull($names[0]->locale);
        self::assertSame('Akira', $names[0]->name);
    }

    public function testOtherNamesAreSplitByLineBreaksAsSynonyms(): void
    {
        $page = CardFactory::fragment([
            'Название (англ.)' => 'Same',
            'Названия (прочие)' => "First<br>Second <br/>\n Same<br><br>First",
        ], 'Same');

        $names = NamesMapper::alternativeNames($page);

        self::assertNotNull($names);
        self::assertSame(['First', 'Second'], array_map(static fn ($n) => $n->name, $names));
        foreach ($names as $name) {
            self::assertNull($name->locale);
            self::assertSame(NameRole::Synonym, $name->role);
        }
    }

    public function testNoNamesIsNull(): void
    {
        self::assertCount(1, NamesMapper::alternativeNames(CardFactory::fixture("card_1500.html")) ?? []);
        self::assertNull(NamesMapper::alternativeNames(CardFactory::fragment([])));
        self::assertNull(NamesMapper::title(WorldartPage::fromBytes('')));
    }
}
