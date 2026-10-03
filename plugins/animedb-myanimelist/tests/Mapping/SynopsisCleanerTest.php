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

use AnimeDb\Plugins\AnimedbMyanimelist\Mapping\SynopsisCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SynopsisCleanerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function cases(): iterable
    {
        yield 'plain text is unchanged' => ['Just a plain synopsis.', 'Just a plain synopsis.'];

        yield '[Written by MAL Rewrite] tail is stripped' => [
            "A story about friendship.\n\n[Written by MAL Rewrite]",
            'A story about friendship.',
        ];

        yield '(Source: ...) tail is stripped' => [
            "A story about friendship.\n\n(Source: Crunchyroll)",
            'A story about friendship.',
        ];

        yield 'both tails stripped when chained' => [
            "A story about friendship.\n\n[Written by MAL Rewrite]\n(Source: MAL News)",
            'A story about friendship.',
        ];

        yield 'bracketed text inside the body is kept' => [
            'He shouts [NO!] and runs away. [Written by MAL Rewrite]',
            'He shouts [NO!] and runs away.',
        ];

        yield '(Source: ...) tail with one level of nested parentheses is stripped' => [
            "A story about friendship.\n\n(Source: Wikipedia (Japanese))",
            'A story about friendship.',
        ];

        yield 'empty string stays empty' => ['', ''];
    }

    #[DataProvider('cases')]
    public function testClean(string $raw, string $expected): void
    {
        self::assertSame($expected, SynopsisCleaner::clean($raw));
    }

    /**
     * card_3455 fixture (issue #177) is a live MyAnimeList response whose synopsis ends with
     * exactly the `[Written by MAL Rewrite]` tail this class must strip.
     */
    public function testStripsTailFromLiveFixture(): void
    {
        $fixture = self::loadFixture('card_3455.json');

        self::assertStringEndsWith('[Written by MAL Rewrite]', $fixture['synopsis']);

        $cleaned = SynopsisCleaner::clean($fixture['synopsis']);

        self::assertStringEndsNotWith('[Written by MAL Rewrite]', $cleaned);
        self::assertStringStartsWith('Timid 16-year-old Rito Yuuki', $cleaned);
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
