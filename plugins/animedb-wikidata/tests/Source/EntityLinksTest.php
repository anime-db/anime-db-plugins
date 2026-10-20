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

namespace AnimeDb\Plugins\AnimedbWikidata\Tests\Source;

use AnimeDb\Plugins\AnimedbWikidata\Source\EntityLinks;
use AnimeDb\Plugins\AnimedbWikidata\Source\SourceTable;
use PHPUnit\Framework\TestCase;

final class EntityLinksTest extends TestCase
{
    public function testDeprecatedValueIsDropped(): void
    {
        self::assertSame(['https://myanimelist.net/anime/2'], $this->mal([['1', 'deprecated'], ['2', 'normal']]));
    }

    public function testOnlyDeprecatedGivesNothing(): void
    {
        self::assertSame([], $this->mal([['1', 'deprecated']]));
    }

    public function testPreferredWinsOverNormal(): void
    {
        self::assertSame(['https://myanimelist.net/anime/2'], $this->mal([['1', 'normal'], ['2', 'preferred']]));
    }

    public function testTwoValuesSkipTheWholeProperty(): void
    {
        self::assertSame([], $this->mal([['1', 'normal'], ['2', 'normal']]));
        self::assertSame([], $this->mal([['1', 'preferred'], ['2', 'preferred']]));
    }

    public function testNonNumericValueIsSkipped(): void
    {
        self::assertSame([], $this->mal([['abc', 'normal']]));
    }

    public function testNonAsciiDigitsAreRejected(): void
    {
        self::assertSame([], $this->mal([["1\u{0663}", 'normal']]));
        self::assertSame([], $this->mal([["\u{FF17}\u{FF17}", 'normal']]));
    }

    public function testUnreadableStatementStillCountsAsAValue(): void
    {
        $entity = ['claims' => ['P4086' => [
            $this->claim('1', 'normal'),
            ['rank' => 'normal', 'mainsnak' => ['snaktype' => 'somevalue']],
        ]]];

        self::assertSame([], EntityLinks::collect(SourceTable::default(), $entity)['urls']);
    }

    public function testCatalogFlagIsSetOnlyByCatalogRows(): void
    {
        $entity = ['claims' => ['P345' => [$this->claim('tt1', 'normal')]]];
        $links = EntityLinks::collect(SourceTable::default(), $entity);

        self::assertSame(['https://www.imdb.com/title/tt1/'], $links['urls']);
        self::assertFalse($links['catalog']);
    }

    public function testSitelinkNeedsNoMultiValueRuleButMustMatchItsPattern(): void
    {
        $entity = ['sitelinks' => [
            'enwiki' => ['url' => 'https://en.wikipedia.org/wiki/A'],
            'ruwiki' => ['url' => 'javascript:alert(1)'],
        ]];

        self::assertSame(['https://en.wikipedia.org/wiki/A'], EntityLinks::collect(SourceTable::default(), $entity)['urls']);
    }

    /**
     * @param list<array{string, string}> $claims
     *
     * @return list<string>
     */
    private function mal(array $claims): array
    {
        $entity = ['claims' => ['P4086' => array_map(fn (array $c) => $this->claim($c[0], $c[1]), $claims)]];

        return EntityLinks::collect(SourceTable::default(), $entity)['urls'];
    }

    /**
     * @return array<string, mixed>
     */
    private function claim(string $value, string $rank): array
    {
        return ['rank' => $rank, 'mainsnak' => ['snaktype' => 'value', 'datavalue' => ['value' => $value, 'type' => 'string']]];
    }
}
