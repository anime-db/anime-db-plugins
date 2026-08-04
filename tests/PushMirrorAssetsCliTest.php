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
 * Smoke-tests tools/push-mirror-assets.php as an actual CLI process, on top of the unit coverage
 * of {@see \AnimeDb\Plugins\Tools\MirrorAssetPublisher} and
 * {@see \AnimeDb\Plugins\Tools\MirrorCredentialsParser}.
 *
 * Only covers the paths that do not require a live FTP(S) server (usage/input validation, and
 * the "no mirrors configured" no-op) — actually reaching a mirror is exercised by the unit tests
 * against a fake {@see \AnimeDb\Plugins\Tools\MirrorTransport}, not here.
 */
final class PushMirrorAssetsCliTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            self::removeDirectory($this->tempDir);
            $this->tempDir = null;
        }
    }

    public function testMissingArgumentsExitsNonZero(): void
    {
        [, $exitCode] = $this->runCli([]);

        self::assertNotSame(0, $exitCode);
    }

    public function testMissingAssetsDirExitsNonZero(): void
    {
        [, $exitCode] = $this->runCli(
            ['animedb-shikimori', '0.2.0', '/does/not/exist', 'MIRROR_CREDS'],
            ['MIRROR_CREDS' => '{}'],
        );

        self::assertNotSame(0, $exitCode);
    }

    public function testUnsetCredsEnvVarExitsZeroAsANoOp(): void
    {
        $assetsDir = $this->makeAssetsDir();

        [$output, $exitCode] = $this->runCli(['animedb-shikimori', '0.2.0', $assetsDir, 'MIRROR_CREDS']);

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    public function testEmptyMirrorCredsObjectExitsZeroAsANoOp(): void
    {
        $assetsDir = $this->makeAssetsDir();

        [$output, $exitCode] = $this->runCli(
            ['animedb-shikimori', '0.2.0', $assetsDir, 'MIRROR_CREDS'],
            ['MIRROR_CREDS' => '{}'],
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    public function testMalformedMirrorCredsJsonExitsNonZero(): void
    {
        $assetsDir = $this->makeAssetsDir();

        [, $exitCode] = $this->runCli(
            ['animedb-shikimori', '0.2.0', $assetsDir, 'MIRROR_CREDS'],
            ['MIRROR_CREDS' => 'not json'],
        );

        self::assertNotSame(0, $exitCode);
    }

    public function testMirrorCredsEntryMissingRequiredFieldExitsNonZero(): void
    {
        $assetsDir = $this->makeAssetsDir();

        [, $exitCode] = $this->runCli(
            ['animedb-shikimori', '0.2.0', $assetsDir, 'MIRROR_CREDS'],
            ['MIRROR_CREDS' => json_encode(['mirror1' => ['host' => 'a.tld']], \JSON_THROW_ON_ERROR)],
        );

        self::assertNotSame(0, $exitCode);
    }

    private function makeAssetsDir(): string
    {
        $this->tempDir = sys_get_temp_dir().'/push-mirror-assets-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);
        file_put_contents($this->tempDir.'/plugin.zip', 'zip-bytes');
        file_put_contents($this->tempDir.'/manifest.json', '{"id":"animedb-shikimori"}');

        return $this->tempDir;
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{0: list<string>, 1: int}
     */
    private function runCli(array $args, array $env = []): array
    {
        $repoRoot = \dirname(__DIR__);

        $envPrefix = '';
        foreach ($env as $name => $value) {
            $envPrefix .= $name.'='.escapeshellarg($value).' ';
        }

        $command = $envPrefix.escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/push-mirror-assets.php');
        foreach ($args as $arg) {
            $command .= ' '.escapeshellarg($arg);
        }

        exec($command.' 2>&1', $output, $exitCode);

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
