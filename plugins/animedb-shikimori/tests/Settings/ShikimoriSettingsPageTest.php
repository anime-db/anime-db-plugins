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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Settings;

use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use AnimeDb\Plugins\AnimedbShikimori\Settings\ShikimoriSettingsPage;
use AnimeDb\Plugins\AnimedbShikimori\Tests\Settings\Fixture\StubTwigFactory;
use PHPUnit\Framework\TestCase;

final class ShikimoriSettingsPageTest extends TestCase
{
    public function testRenderShowsCurrentlyStoredApiEndpoint(): void
    {
        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('read')->willReturn(['api_endpoint' => 'https://shikimori.one']);

        $page = new ShikimoriSettingsPage($settings, StubTwigFactory::create());

        self::assertStringContainsString('value="https://shikimori.one"', $page->render());
    }

    public function testRenderShowsDefaultHintWhenStoreIsEmpty(): void
    {
        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('read')->willReturn([]);

        $page = new ShikimoriSettingsPage($settings, StubTwigFactory::create());
        $html = $page->render();

        self::assertStringContainsString('value=""', $html);
        self::assertStringContainsString('https://shikimori.io', $html);
    }
}
