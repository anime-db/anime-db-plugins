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

use AnimeDb\Plugins\AnimedbWikidata\Source\SourceTable;
use PHPUnit\Framework\TestCase;

final class SourceTableTest extends TestCase
{
    public function testEveryRowOfTheShippedTableIsValidAndItsExampleHolds(): void
    {
        // loading validates each row, including the example round trip
        $table = SourceTable::default();

        self::assertCount(11, $table->all());
        foreach ($table->all() as $source) {
            self::assertTrue($source->acceptsId($source->exampleId), $source->name);
            self::assertSame($source->exampleUrl, $source->buildUrl($source->exampleId), $source->name);
            self::assertTrue(($source->property === null) !== ($source->sitelink === null), $source->name);
            if ($source->property !== null) {
                self::assertStringContainsString('{id}', (string) $source->urlTemplate, $source->name);
            }
        }
    }

    public function testCatalogHostsComeFromCatalogRowsOnly(): void
    {
        self::assertEqualsCanonicalizing(
            ['myanimelist.net', 'anidb.net', 'animenewsnetwork.com', 'anilist.co'],
            SourceTable::default()->catalogHosts(),
        );
    }

    /**
     * @param array<string, mixed> $patch
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRows')]
    public function testInvalidRowIsRejected(array $patch): void
    {
        $row = array_filter($patch + $this->row(), static fn ($value) => $value !== null);

        $this->expectException(\InvalidArgumentException::class);
        SourceTable::fromArray([$row]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidRows(): iterable
    {
        yield 'both property and sitelink' => [['sitelink' => 'ruwiki']];
        yield 'neither property nor sitelink' => [['property' => null]];
        yield 'url without {id}' => [['url' => 'https://example.com/anime']];
        yield 'sitelink with url' => [['property' => null, 'sitelink' => 'ruwiki']];
        yield 'broken pattern' => [['id' => '^(\\d+']];
        yield 'catalog not boolean' => [['catalog' => 'yes']];
        yield 'no example' => [['example' => null]];
        yield 'example url mismatch' => [['example' => ['id' => '1', 'url' => 'https://example.com/anime/2']]];
        yield 'example id not accepted' => [['example' => ['id' => 'x', 'url' => 'https://example.com/anime/x']]];
        yield 'bad property' => [['property' => '4086']];
    }

    public function testDuplicateSourceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SourceTable::fromArray([$this->row(), $this->row()]);
    }

    public function testEmptyTableIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SourceTable::fromArray([]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'name' => 'Example',
            'property' => 'P1',
            'url' => 'https://example.com/anime/{id}',
            'id' => '^\\d+\\z',
            'catalog' => true,
            'example' => ['id' => '1', 'url' => 'https://example.com/anime/1'],
        ];
    }
}
