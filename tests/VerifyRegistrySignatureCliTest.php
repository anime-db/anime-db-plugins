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

final class VerifyRegistrySignatureCliTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            self::removeDirectory($this->tempDir);
            $this->tempDir = null;
        }
    }

    public function testGenuineSignatureExitsZero(): void
    {
        [$filePath, $signaturePath, $publicKeyPath] = $this->prepareFixture('{"sequence":1,"asset_mirrors":[],"plugins":[]}');

        [$output, $exitCode] = $this->runCli($filePath, $signaturePath, $publicKeyPath);

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    public function testTamperedFileByteExitsNonZero(): void
    {
        [$filePath, $signaturePath, $publicKeyPath] = $this->prepareFixture('{"sequence":1,"asset_mirrors":[],"plugins":[]}');

        // Flip a single byte of the signed file after the signature was produced over it.
        file_put_contents($filePath, '{"sequence":2,"asset_mirrors":[],"plugins":[]}');

        [, $exitCode] = $this->runCli($filePath, $signaturePath, $publicKeyPath);

        self::assertNotSame(0, $exitCode);
    }

    /**
     * @return array{0: string, 1: string, 2: string} file, signature and public-key paths
     */
    private function prepareFixture(string $contents): array
    {
        $this->tempDir = sys_get_temp_dir().'/verify-registry-signature-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);

        $filePath = $this->tempDir.'/plugins-registry.json';
        file_put_contents($filePath, $contents);

        $signer = new PluginRegistrySigner();
        $keyPair = $signer->generateKeyPair();

        $publicKeyPath = $this->tempDir.'/public.key';
        file_put_contents($publicKeyPath, $keyPair['public']."\n");

        $signaturePath = $this->tempDir.'/plugins-registry.json.sig';
        file_put_contents($signaturePath, $signer->sign($contents, $keyPair['secret'])."\n");

        return [$filePath, $signaturePath, $publicKeyPath];
    }

    /**
     * @return array{0: list<string>, 1: int}
     */
    private function runCli(string $filePath, string $signaturePath, string $publicKeyPath): array
    {
        $repoRoot = \dirname(__DIR__);

        exec(
            escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/verify-registry-signature.php').' '
                .escapeshellarg($filePath).' '
                .escapeshellarg($signaturePath).' '
                .escapeshellarg($publicKeyPath).' 2>&1',
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
