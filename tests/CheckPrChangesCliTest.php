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

namespace AnimeDb\Plugins\Tools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Smoke-tests tools/check-pr-changes.php as an actual CLI process, on top of the unit
 * coverage of {@see \AnimeDb\Plugins\Tools\PrChangeChecker} in {@see PrChangeCheckerTest}.
 */
final class CheckPrChangesCliTest extends TestCase
{
    public function testSinglePluginArgvPrintsIdAndExitsZero(): void
    {
        $repoRoot = \dirname(__DIR__);
        exec(
            escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/check-pr-changes.php').' '
                .'plugins/animedb-shikimori/manifest.json plugins/animedb-shikimori/src/ShikimoriFiller.php',
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode);
        self::assertSame(['animedb-shikimori'], $output);
    }

    public function testMultiplePluginsViaStdinExitsNonZero(): void
    {
        $repoRoot = \dirname(__DIR__);
        $cmd = 'printf %s '.escapeshellarg("plugins/animedb-shikimori/manifest.json\nplugins/johnsmith-example/manifest.json")
            .' | '.escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/check-pr-changes.php').' 2>&1';
        exec($cmd, $output, $exitCode);

        self::assertNotSame(0, $exitCode);
    }
}
