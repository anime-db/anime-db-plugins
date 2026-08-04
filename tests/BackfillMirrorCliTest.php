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
 * Smoke-tests tools/backfill-mirror.php as an actual CLI process, on top of the unit coverage of
 * {@see \AnimeDb\Plugins\Tools\MirrorBackfillPublisher}.
 *
 * Only covers the paths that do not require a real `gh` CLI / GitHub network or a live FTP(S)
 * server (usage/input validation) — actually backfilling a mirror is exercised by the unit tests
 * against fakes, not here. In every case here the active-mirrors file must be left untouched.
 */
final class BackfillMirrorCliTest extends TestCase
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

    public function testMissingActiveMirrorsFileExitsNonZero(): void
    {
        [, $exitCode] = $this->runCli(
            ['mirror1', 'MIRROR_CREDS', '/does/not/exist'],
            ['MIRROR_CREDS' => '{}'],
        );

        self::assertNotSame(0, $exitCode);
    }

    public function testUnsetCredsEnvVarExitsNonZero(): void
    {
        $activeMirrorsPath = $this->makeActiveMirrorsFile('');

        [, $exitCode] = $this->runCli(['mirror1', 'MIRROR_CREDS', $activeMirrorsPath]);

        self::assertNotSame(0, $exitCode);
        self::assertSame('', file_get_contents($activeMirrorsPath));
    }

    public function testUnknownMirrorIdExitsNonZero(): void
    {
        $activeMirrorsPath = $this->makeActiveMirrorsFile('');

        [, $exitCode] = $this->runCli(
            ['mirror1', 'MIRROR_CREDS', $activeMirrorsPath],
            ['MIRROR_CREDS' => '{}'],
        );

        self::assertNotSame(0, $exitCode);
        self::assertSame('', file_get_contents($activeMirrorsPath));
    }

    public function testMalformedMirrorCredsJsonExitsNonZero(): void
    {
        $activeMirrorsPath = $this->makeActiveMirrorsFile('');

        [, $exitCode] = $this->runCli(
            ['mirror1', 'MIRROR_CREDS', $activeMirrorsPath],
            ['MIRROR_CREDS' => 'not json'],
        );

        self::assertNotSame(0, $exitCode);
        self::assertSame('', file_get_contents($activeMirrorsPath));
    }

    private function makeActiveMirrorsFile(string $content): string
    {
        $this->tempDir = sys_get_temp_dir().'/backfill-mirror-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);
        $path = $this->tempDir.'/active-mirrors';
        file_put_contents($path, $content);

        return $path;
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

        $command = $envPrefix.escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/backfill-mirror.php');
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
