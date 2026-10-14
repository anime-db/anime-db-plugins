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

namespace AnimeDb\Plugins\AnimedbWorldart\Tests\Page;

use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use PHPUnit\Framework\TestCase;

final class WorldartPageTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixture/'.$name);
    }

    public function testFixturesAreRawWindows1251(): void
    {
        // not valid UTF-8: the fixtures were not re-encoded
        self::assertFalse(mb_check_encoding($this->fixture('card_6.html'), 'UTF-8'));
        self::assertFalse(mb_check_encoding($this->fixture('search_naruto.html'), 'UTF-8'));
    }

    public function testTitleOfCardIsReadWithoutGarbling(): void
    {
        $page = WorldartPage::fromBytes($this->fixture('card_6.html'));

        self::assertSame('Эксперименты Лэйн', $page->title());
        self::assertNull($page->refreshTarget());
    }

    public function testNoindexBlockIsKept(): void
    {
        $page = WorldartPage::fromBytes($this->fixture('card_6.html'));

        $links = $page->xpath()->query('//x:noindex//x:a[contains(@href, "anidb.net")]');
        self::assertSame(1, $links->length);
    }

    public function testPageWithoutTitle(): void
    {
        self::assertNull(WorldartPage::fromBytes($this->fixture('search_naruto.html'))->title());
        self::assertNull(WorldartPage::fromBytes('')->title());
    }

    public function testRefreshTarget(): void
    {
        self::assertSame(
            '/animation/animation.php?id=6',
            WorldartPage::fromBytes($this->fixture('search_single_result_redirect.html'))->refreshTarget(),
        );
        self::assertSame(
            'http://www.world-art.ru/not_connect.html',
            WorldartPage::fromBytes($this->fixture('search_not_connect_redirect.html'))->refreshTarget(),
        );
    }
}
