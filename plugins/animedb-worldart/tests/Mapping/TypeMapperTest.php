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

use AnimeDb\PluginContracts\Model\AnimeType;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\TypeMapper;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use AnimeDb\Plugins\AnimedbWorldart\Tests\Support\CardFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypeMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?AnimeType, ?int, ?int}>
     */
    public static function variants(): iterable
    {
        yield 'tv' => ['ТВ (24 эп.), 25 мин.', AnimeType::Tv, 24, 25];
        yield 'tv without duration' => ['ТВ (39 эп.)', AnimeType::Tv, 39, null];
        yield 'ova' => ['OVA (1 эп.), 21 мин.', AnimeType::Ova, 1, 21];
        yield 'web' => ['Web (10 эп.), 5 мин.', AnimeType::Ona, 10, 5];
        yield 'special is dropped' => ['ТВ (11 эп. + спэшл), 4 мин.', AnimeType::Tv, 11, 4];
        yield 'feature film' => ['полнометражный фильм, 90 мин.', AnimeType::Movie, 1, 90];
        yield 'short film' => ['короткометражный фильм, 45 мин.', AnimeType::Movie, 1, 45];
        yield 'tv special' => ['ТВ-спэшл, 30 мин.', AnimeType::Special, null, 30];
        yield 'oav' => ['OAV (2 эп.), 30 мин.', AnimeType::Ova, 2, 30];
        yield 'ona' => ['ONA (6 эп.), 10 мин.', AnimeType::Ona, 6, 10];
        yield 'music video' => ['музыкальное видео, 4 мин.', AnimeType::Music, null, 4];
        yield 'unknown kind keeps numbers' => ['что-то новое (3 эп.), 7 мин.', null, 3, 7];
    }

    #[DataProvider('variants')]
    public function testVariants(string $value, ?AnimeType $type, ?int $episodes, ?int $duration): void
    {
        $page = CardFactory::fragment(['Тип' => $value]);

        self::assertSame($type, TypeMapper::type($page));
        self::assertSame($episodes, TypeMapper::episodesCount($page));
        self::assertSame($duration, TypeMapper::durationMinutes($page));
    }

    public function testFixtures(): void
    {
        $expected = [
            'card_11466.html' => [AnimeType::Tv, 28, 25],
            'card_1.html' => [AnimeType::Movie, 1, 124],
            'card_1500.html' => [AnimeType::Tv, 39, 5],
            'card_10000.html' => [AnimeType::Movie, 1, 45],
            'card_ova_100.html' => [AnimeType::Ova, 1, 25],
            'card_tv_special_8000.html' => [AnimeType::Tv, 11, 4],
            'card_web_11000.html' => [AnimeType::Ona, 10, 5],
        ];
        foreach ($expected as $fixture => [$type, $episodes, $duration]) {
            $page = CardFactory::fixture($fixture);

            self::assertSame($type, TypeMapper::type($page), $fixture);
            self::assertSame($episodes, TypeMapper::episodesCount($page), $fixture);
            self::assertSame($duration, TypeMapper::durationMinutes($page), $fixture);
        }
    }

    public function testNoLabelIsNull(): void
    {
        foreach ([WorldartPage::fromBytes(''), CardFactory::fragment([])] as $page) {
            self::assertNull(TypeMapper::type($page));
            self::assertNull(TypeMapper::episodesCount($page));
            self::assertNull(TypeMapper::durationMinutes($page));
        }
    }
}
