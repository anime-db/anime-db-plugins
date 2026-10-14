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

use AnimeDb\Plugins\AnimedbWorldart\Mapping\CountryMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\DatesMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\DescriptionMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\NamesMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\TaxonomyMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\TypeMapper;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use AnimeDb\Plugins\AnimedbWorldart\Tests\Support\CardFactory;
use PHPUnit\Framework\TestCase;

/**
 * A field that did not parse stays `null`, an unknown label is ignored, nothing throws.
 */
final class UnparsedFieldsTest extends TestCase
{
    public function testGarbagePageGivesNulls(): void
    {
        foreach (['', 'not html at all', "\xff\xfe\x00<<>>", '<td class="review"><b>Тип</b></td>'] as $bytes) {
            $page = WorldartPage::fromBytes($bytes);

            self::assertNull(NamesMapper::title($page));
            self::assertNull(NamesMapper::alternativeNames($page));
            self::assertNull(DescriptionMapper::map($page));
            self::assertNull(TaxonomyMapper::demographic($page));
            self::assertSame(['genres' => [], 'themes' => []], TaxonomyMapper::genresAndThemes($page));
            self::assertNull(TypeMapper::type($page));
            self::assertNull(TypeMapper::episodesCount($page));
            self::assertNull(TypeMapper::durationMinutes($page));
            self::assertSame(['premiere' => null, 'end' => null], DatesMapper::map($page));
            self::assertNull(CountryMapper::map($page));
        }
    }

    public function testUnknownLabelIsIgnored(): void
    {
        $page = CardFactory::fragment(['Неизвестная метка' => 'значение', 'Тип' => 'ТВ (2 эп.)']);

        self::assertNull(NamesMapper::alternativeNames($page));
        self::assertSame(2, TypeMapper::episodesCount($page));
    }
}
