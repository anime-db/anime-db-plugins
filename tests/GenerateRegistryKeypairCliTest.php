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

use AnimeDb\Plugins\Tools\PluginRegistrySigner;
use PHPUnit\Framework\TestCase;

final class GenerateRegistryKeypairCliTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            self::removeDirectory($this->tempDir);
            $this->tempDir = null;
        }
    }

    public function testGeneratesAUsableKeyPair(): void
    {
        $repoRoot = \dirname(__DIR__);
        $this->tempDir = sys_get_temp_dir().'/generate-registry-keypair-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);
        $publicKeyPath = $this->tempDir.'/public.key';

        exec(
            escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/generate-registry-keypair.php').' '
                .escapeshellarg($publicKeyPath).' 2>'.escapeshellarg($this->tempDir.'/stderr.txt'),
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode);
        self::assertFileExists($publicKeyPath);
        self::assertCount(1, $output);

        $publicKey = trim((string) file_get_contents($publicKeyPath));
        $secretKey = trim($output[0]);

        $signer = new PluginRegistrySigner();
        $signature = $signer->sign('a test message', $secretKey);
        self::assertTrue($signer->verify('a test message', $signature, $publicKey));
    }

    public function testRefusesToOverwriteAnExistingKeyFile(): void
    {
        $repoRoot = \dirname(__DIR__);
        $this->tempDir = sys_get_temp_dir().'/generate-registry-keypair-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);
        $publicKeyPath = $this->tempDir.'/public.key';
        file_put_contents($publicKeyPath, 'existing-key');

        exec(
            escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/generate-registry-keypair.php').' '
                .escapeshellarg($publicKeyPath).' 2>&1',
            $output,
            $exitCode,
        );

        self::assertNotSame(0, $exitCode);
        self::assertSame('existing-key', file_get_contents($publicKeyPath));
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
