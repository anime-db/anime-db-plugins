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

use PHPUnit\Framework\TestCase;

/**
 * Smoke-tests tools/validate-plugin.php as an actual CLI process, on top of the unit
 * coverage of {@see \AnimeDb\Plugins\Tools\PluginValidator} in {@see PluginValidatorTest} —
 * the deliverable of this issue is the script itself, not just the class behind it.
 */
final class ValidatePluginCliTest extends TestCase
{
    public function testValidPluginExitsZero(): void
    {
        $repoRoot = \dirname(__DIR__);
        exec(
            escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/validate-plugin.php').' '
                .escapeshellarg($repoRoot.'/plugins/animedb-shikimori').' 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    public function testMissingPluginDirExitsNonZero(): void
    {
        $repoRoot = \dirname(__DIR__);
        exec(
            escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/validate-plugin.php').' '
                .escapeshellarg($repoRoot.'/plugins/does-not-exist').' 2>&1',
            $output,
            $exitCode,
        );

        self::assertNotSame(0, $exitCode);
    }
}
