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

namespace AnimeDb\Plugins\Tools\Tests;

use AnimeDb\Plugins\Tools\ActiveMirrorsFile;
use PHPUnit\Framework\TestCase;

final class ActiveMirrorsFileTest extends TestCase
{
    public function testEmptyContentYieldsNoIds(): void
    {
        self::assertSame([], (new ActiveMirrorsFile())->parse(''));
    }

    public function testBlankLinesAndCommentsAreIgnored(): void
    {
        $content = "# comment\n\nreg-ru\n\n# another comment\nsecond-mirror\n";

        self::assertSame(['reg-ru', 'second-mirror'], (new ActiveMirrorsFile())->parse($content));
    }

    public function testIdsAreSortedAndDeduplicated(): void
    {
        self::assertSame(
            ['aaa-mirror', 'zzz-mirror'],
            (new ActiveMirrorsFile())->parse("zzz-mirror\naaa-mirror\nzzz-mirror\n"),
        );
    }

    public function testInvalidIdThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ActiveMirrorsFile())->parse("Reg_RU\n");
    }

    public function testSerializeSortsDeduplicatesAndAppendsTrailingNewline(): void
    {
        self::assertSame(
            "aaa-mirror\nzzz-mirror\n",
            (new ActiveMirrorsFile())->serialize(['zzz-mirror', 'aaa-mirror', 'zzz-mirror']),
        );
    }

    public function testSerializeOfEmptyListIsEmptyString(): void
    {
        self::assertSame('', (new ActiveMirrorsFile())->serialize([]));
    }

    public function testSerializeThenParseRoundTrips(): void
    {
        $file = new ActiveMirrorsFile();
        $ids = ['aaa-mirror', 'zzz-mirror'];

        self::assertSame($ids, $file->parse($file->serialize($ids)));
    }
}
