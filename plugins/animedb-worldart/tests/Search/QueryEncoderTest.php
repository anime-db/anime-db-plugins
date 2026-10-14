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

namespace AnimeDb\Plugins\AnimedbWorldart\Tests\Search;

use AnimeDb\Plugins\AnimedbWorldart\Search\QueryEncoder;
use PHPUnit\Framework\TestCase;

final class QueryEncoderTest extends TestCase
{
    public function testCyrillicBecomesWindows1251Bytes(): void
    {
        self::assertSame("\xCD\xE0\xF0\xF3\xF2\xEE", QueryEncoder::encode('Наруто'));
    }

    public function testLatinIsKept(): void
    {
        self::assertSame('naruto 2', QueryEncoder::encode(' naruto 2 '));
    }

    public function testNothingToSearchBy(): void
    {
        self::assertNull(QueryEncoder::encode(''));
        self::assertNull(QueryEncoder::encode("  \t "));
        self::assertNull(QueryEncoder::encode('ナルト'));
        self::assertNull(QueryEncoder::encode('ナルト 疾風伝'));
        self::assertNull(QueryEncoder::encode('???'));
    }

    public function testMixedQueryKeepsTheEncodableLetters(): void
    {
        self::assertNotNull(QueryEncoder::encode('Naruto ナルト'));
    }
}
