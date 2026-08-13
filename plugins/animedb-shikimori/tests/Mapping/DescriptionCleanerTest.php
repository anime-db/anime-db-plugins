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

use AnimeDb\Plugins\AnimedbShikimori\Mapping\DescriptionCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DescriptionCleanerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function cases(): iterable
    {
        yield 'plain text is unchanged' => ['Just a plain description.', 'Just a plain description.'];

        yield 'paired tag keeps inner text' => [
            '[character=7407]Девятихвостый лис[/character] напал на деревню.',
            'Девятихвостый лис напал на деревню.',
        ];

        yield 'wiki link without pipe' => ['See [[Naruto]] for details.', 'See Naruto for details.'];

        yield 'wiki link with pipe keeps display text' => [
            'See [[naruto-uzumaki|Naruto Uzumaki]] for details.',
            'See Naruto Uzumaki for details.',
        ];

        yield 'unpaired bracket annotation is dropped entirely' => [
            'His father [波風ミナト] sealed the beast.',
            'His father sealed the beast.',
        ];

        yield 'unclosed dangling tag keeps its text' => [
            '[spoiler]He survives the fight.',
            'He survives the fight.',
        ];

        yield 'nested tags of different names unwrap fully' => [
            '[character=1]Interior [spoiler]nested[/spoiler] text[/character]',
            'Interior nested text',
        ];

        yield 'dangling closing tag alone is stripped' => [
            'The end.[/spoiler]',
            'The end.',
        ];

        yield 'url tag with attribute is stripped, text kept' => [
            'Official site: [url=https://example.tld]here[/url].',
            'Official site: here.',
        ];

        yield 'mixed hostile input' => [
            "Plain [character=1]Name[/character] text with [波風ミナト] annotation\nand [[a|Link Text]] link.",
            'Plain Name text with annotation and Link Text link.',
        ];

        yield 'excess whitespace and newlines collapse' => [
            "Line one.\n\n\n   Line   two.",
            'Line one. Line two.',
        ];

        yield 'empty string stays empty' => ['', ''];
    }

    #[DataProvider('cases')]
    public function testClean(string $raw, string $expected): void
    {
        self::assertSame($expected, DescriptionCleaner::clean($raw));
    }
}
