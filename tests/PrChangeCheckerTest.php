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

use AnimeDb\Plugins\Tools\PrChangeChecker;
use PHPUnit\Framework\TestCase;

final class PrChangeCheckerTest extends TestCase
{
    public function testSinglePluginResolvesToItsId(): void
    {
        $result = (new PrChangeChecker())->check([
            'plugins/animedb-shikimori/manifest.json',
            'plugins/animedb-shikimori/src/ShikimoriFiller.php',
        ]);

        self::assertTrue($result->isValid());
        self::assertSame('animedb-shikimori', $result->pluginId);
    }

    public function testMultiplePluginsAreRejected(): void
    {
        $result = (new PrChangeChecker())->check([
            'plugins/animedb-shikimori/manifest.json',
            'plugins/johnsmith-example/manifest.json',
        ]);

        self::assertFalse($result->isValid());
        self::assertNull($result->pluginId);
        self::assertNotSame([], $result->errors);
    }

    public function testPathOutsidePluginsIsRejected(): void
    {
        $result = (new PrChangeChecker())->check([
            'plugins/animedb-shikimori/manifest.json',
            'README.md',
        ]);

        self::assertFalse($result->isValid());
        self::assertNull($result->pluginId);
    }

    public function testEditingPluginsDirectoryJsonIsRejected(): void
    {
        $result = (new PrChangeChecker())->check([
            'plugins/animedb-shikimori/manifest.json',
            'plugins-directory.json',
        ]);

        self::assertFalse($result->isValid());
        self::assertNull($result->pluginId);
    }

    public function testEmptyPathListIsRejected(): void
    {
        $result = (new PrChangeChecker())->check([]);

        self::assertFalse($result->isValid());
    }

    public function testMalformedPluginIdIsRejected(): void
    {
        $result = (new PrChangeChecker())->check([
            'plugins/../secret/x',
        ]);

        self::assertFalse($result->isValid());
        self::assertNull($result->pluginId);
    }
}
