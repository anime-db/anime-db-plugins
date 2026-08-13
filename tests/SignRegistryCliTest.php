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

use AnimeDb\Plugins\Tools\PluginRegistrySigner;
use PHPUnit\Framework\TestCase;

/**
 * Smoke-tests tools/sign-registry.php as an actual CLI process, complementing the unit coverage
 * of {@see PluginRegistrySigner} in {@see PluginRegistrySignerTest}.
 */
final class SignRegistryCliTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            self::removeDirectory($this->tempDir);
            $this->tempDir = null;
        }
    }

    public function testSigningProducesASignatureVerifiableAgainstThePublicKey(): void
    {
        $this->tempDir = sys_get_temp_dir().'/sign-registry-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);

        $filePath = $this->tempDir.'/plugins-registry.json';
        file_put_contents($filePath, '{"sequence":1,"asset_mirrors":[],"plugins":[]}');

        $signer = new PluginRegistrySigner();
        $keyPair = $signer->generateKeyPair();

        [$output, $exitCode] = $this->runCli($filePath, ['REGISTRY_SIGNING_KEY' => $keyPair['secret']]);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertCount(1, $output);
        self::assertTrue(
            $signer->verify((string) file_get_contents($filePath), $output[0], $keyPair['public']),
        );
    }

    public function testMissingEnvVarExitsNonZero(): void
    {
        $this->tempDir = sys_get_temp_dir().'/sign-registry-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);

        $filePath = $this->tempDir.'/plugins-registry.json';
        file_put_contents($filePath, '{"sequence":1,"asset_mirrors":[],"plugins":[]}');

        [, $exitCode] = $this->runCli($filePath, []);

        self::assertNotSame(0, $exitCode);
    }

    public function testMissingFileExitsNonZero(): void
    {
        $this->tempDir = sys_get_temp_dir().'/sign-registry-cli-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);

        [, $exitCode] = $this->runCli(
            $this->tempDir.'/does-not-exist.json',
            ['REGISTRY_SIGNING_KEY' => (new PluginRegistrySigner())->generateKeyPair()['secret']],
        );

        self::assertNotSame(0, $exitCode);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{0: list<string>, 1: int}
     */
    private function runCli(string $filePath, array $env): array
    {
        $repoRoot = \dirname(__DIR__);

        $envPrefix = '';
        foreach ($env as $name => $value) {
            $envPrefix .= $name.'='.escapeshellarg($value).' ';
        }

        exec(
            $envPrefix.escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/sign-registry.php').' '
                .escapeshellarg($filePath).' '
                .escapeshellarg('REGISTRY_SIGNING_KEY').' 2>&1',
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
