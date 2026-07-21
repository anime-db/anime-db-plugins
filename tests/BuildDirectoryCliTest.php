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
 * Smoke-tests tools/build-directory.php as an actual CLI process, on top of the unit coverage
 * of {@see \AnimeDb\Plugins\Tools\PluginDirectoryBuilder} in {@see PluginDirectoryBuilderTest} —
 * the deliverable of this issue is the script itself, not just the class behind it.
 */
final class BuildDirectoryCliTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            self::removeDirectory($this->tempDir);
            $this->tempDir = null;
        }
    }

    public function testBuildingRealShikimoriPluginExitsZeroAndPrintsDirectory(): void
    {
        $repoRoot = \dirname(__DIR__);
        $this->tempDir = sys_get_temp_dir().'/build-directory-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);

        $publishedVersionsPath = $this->tempDir.'/published-versions.json';
        file_put_contents($publishedVersionsPath, json_encode([
            ['id' => 'animedb-shikimori', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'abc123'],
        ], \JSON_THROW_ON_ERROR));

        [$output, $exitCode] = $this->runCli($repoRoot.'/plugins', $publishedVersionsPath);

        self::assertSame(0, $exitCode, implode("\n", $output));

        $directory = json_decode(implode("\n", $output), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(
            ['https://github.com/anime-db/anime-db-plugins/releases/download/<id>/<version>/<file>'],
            $directory['asset_mirrors'],
        );
        self::assertCount(1, $directory['plugins']);
        self::assertSame('animedb-shikimori', $directory['plugins'][0]['id']);
        self::assertSame(
            json_decode(file_get_contents($repoRoot.'/plugins/animedb-shikimori/manifest.json'), true, 512, \JSON_THROW_ON_ERROR),
            $directory['plugins'][0]['manifest'],
        );
        self::assertSame(
            [['version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'abc123']],
            $directory['plugins'][0]['versions'],
        );
    }

    public function testOutputIsByteIdenticalAcrossTwoRuns(): void
    {
        $repoRoot = \dirname(__DIR__);
        $this->tempDir = sys_get_temp_dir().'/build-directory-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);

        $publishedVersionsPath = $this->tempDir.'/published-versions.json';
        file_put_contents($publishedVersionsPath, json_encode([
            ['id' => 'animedb-shikimori', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'abc123'],
        ], \JSON_THROW_ON_ERROR));

        [$firstOutput, $firstExitCode] = $this->runCli($repoRoot.'/plugins', $publishedVersionsPath);
        [$secondOutput, $secondExitCode] = $this->runCli($repoRoot.'/plugins', $publishedVersionsPath);

        self::assertSame(0, $firstExitCode);
        self::assertSame(0, $secondExitCode);
        self::assertSame($firstOutput, $secondOutput);
    }

    public function testMissingPluginsDirExitsNonZero(): void
    {
        $repoRoot = \dirname(__DIR__);
        $this->tempDir = sys_get_temp_dir().'/build-directory-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);

        $publishedVersionsPath = $this->tempDir.'/published-versions.json';
        file_put_contents($publishedVersionsPath, json_encode([], \JSON_THROW_ON_ERROR));

        [, $exitCode] = $this->runCli($repoRoot.'/plugins/does-not-exist', $publishedVersionsPath);

        self::assertNotSame(0, $exitCode);
    }

    public function testMalformedPublishedVersionsExitsNonZero(): void
    {
        $repoRoot = \dirname(__DIR__);
        $this->tempDir = sys_get_temp_dir().'/build-directory-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);

        $publishedVersionsPath = $this->tempDir.'/published-versions.json';
        file_put_contents($publishedVersionsPath, 'not json');

        [, $exitCode] = $this->runCli($repoRoot.'/plugins', $publishedVersionsPath);

        self::assertNotSame(0, $exitCode);
    }

    /**
     * @return array{0: list<string>, 1: int}
     */
    private function runCli(string $pluginsDir, string $publishedVersionsPath): array
    {
        $repoRoot = \dirname(__DIR__);

        exec(
            escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/build-directory.php').' '
                .escapeshellarg($pluginsDir).' '
                .escapeshellarg($publishedVersionsPath).' 2>&1',
            $output,
            $exitCode,
        );

        return [$output, $exitCode];
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            unlink($dir.'/'.$entry);
        }

        rmdir($dir);
    }
}
