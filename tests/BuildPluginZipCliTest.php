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
 * Smoke-tests tools/build-plugin-zip.php as an actual CLI process, on top of the unit
 * coverage of {@see \AnimeDb\Plugins\Tools\PluginZipBuilder} in {@see PluginZipBuilderTest} —
 * the deliverable of this issue is the script itself, not just the class behind it.
 */
final class BuildPluginZipCliTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            foreach (glob($this->tempDir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
            $this->tempDir = null;
        }
    }

    public function testBuildingRealShikimoriPluginExitsZeroAndPrintsSha256(): void
    {
        $repoRoot = \dirname(__DIR__);
        $this->tempDir = sys_get_temp_dir().'/build-plugin-zip-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);
        $outZip = $this->tempDir.'/plugin.zip';

        exec(
            escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/build-plugin-zip.php').' '
                .escapeshellarg($repoRoot.'/plugins/animedb-shikimori').' '
                .escapeshellarg($outZip).' 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertFileExists($outZip);

        $expectedSha256 = hash_file('sha256', $outZip);
        self::assertSame(['sha256: '.$expectedSha256], $output);
        self::assertSame($expectedSha256."\n", file_get_contents($this->tempDir.'/sha256'));
    }

    public function testMissingPluginDirExitsNonZero(): void
    {
        $repoRoot = \dirname(__DIR__);
        $this->tempDir = sys_get_temp_dir().'/build-plugin-zip-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);

        exec(
            escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/build-plugin-zip.php').' '
                .escapeshellarg($repoRoot.'/plugins/does-not-exist').' '
                .escapeshellarg($this->tempDir.'/plugin.zip').' 2>&1',
            $output,
            $exitCode,
        );

        self::assertNotSame(0, $exitCode);
    }
}
