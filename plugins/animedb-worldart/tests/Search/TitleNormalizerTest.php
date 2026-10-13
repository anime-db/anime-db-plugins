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

use AnimeDb\Plugins\AnimedbWorldart\Search\TitleNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TitleNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function titles(): iterable
    {
        yield 'case' => ['NaRuTo', 'naruto'];
        yield 'season mark' => ['Наруто [ТВ-1]', 'наруто'];
        yield 'season mark of any number' => ['Наруто [тв-12]', 'наруто'];
        yield 'yo' => ['Ёжик Серёжа', 'ежик сережа'];
        yield 'punctuation and spaces' => ['  Naruto:   Shippuuden! ', 'naruto shippuuden'];
        yield 'other script is kept' => ['Naruto 疾風伝', 'naruto 疾風伝'];
        yield 'digits' => ['Наруто OVA-3', 'наруто ova 3'];
        yield 'hieroglyphs' => ['ナルト', 'ナルト'];
        yield 'only punctuation' => [' -:! ', ''];
    }

    #[DataProvider('titles')]
    public function testNormalize(string $title, string $expected): void
    {
        self::assertSame($expected, TitleNormalizer::normalize($title));
    }
}
