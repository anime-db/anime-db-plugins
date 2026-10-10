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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\Index;

use AnimeDb\Plugins\AnimedbAnidb\Index\TitleNormalizer;
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
        yield 'punctuation' => ['Boruto: Naruto Next Generations!', 'boruto naruto next generations'];
        yield 'spaces' => ["  Cowboy \t  Bebop  ", 'cowboy bebop'];
        yield 'cyrillic' => ['Тетрадь Смерти', 'тетрадь смерти'];
        yield 'japanese kept' => ['カウボーイ・ビバップ', 'カウボーイ ビバップ'];
        yield 'only punctuation' => ['!!!', ''];
    }

    #[DataProvider('titles')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, TitleNormalizer::normalize($input));
    }
}
