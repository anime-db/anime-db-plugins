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

use AnimeDb\PluginContracts\Manifest\ManifestValidator;
use AnimeDb\Plugins\Tools\PluginZipBuilder;
use PHPUnit\Framework\TestCase;

final class PluginZipBuilderTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    public function testArchiveContainsManifestAtRootAndSrc(): void
    {
        $pluginDir = $this->createPluginFixture();
        $outZip = $this->tempPath('out.zip');

        (new PluginZipBuilder())->build($pluginDir, $outZip);

        $names = self::listEntries($outZip);

        self::assertContains('manifest.json', $names);
        self::assertContains('src/Widget.php', $names);
        self::assertContains('README.md', $names);
    }

    public function testArchiveExcludesDevArtifacts(): void
    {
        $pluginDir = $this->createPluginFixture();
        $outZip = $this->tempPath('out.zip');

        (new PluginZipBuilder())->build($pluginDir, $outZip);

        $names = self::listEntries($outZip);

        foreach ($names as $name) {
            self::assertStringStartsNotWith('vendor/', $name);
            self::assertStringStartsNotWith('tests/', $name);
            self::assertNotSame('composer.lock', $name);
            self::assertStringStartsNotWith('.git', basename($name));
            self::assertStringStartsNotWith('.php-cs-fixer', basename($name));
        }
    }

    public function testShaIsStableAcrossTwoBuildsOfTheSameInput(): void
    {
        $pluginDir = $this->createPluginFixture();

        $sha1 = (new PluginZipBuilder())->build($pluginDir, $this->tempPath('first.zip'));
        $sha2 = (new PluginZipBuilder())->build($pluginDir, $this->tempPath('second.zip'));

        self::assertSame($sha1, $sha2);
        self::assertSame(hash_file('sha256', $this->tempPath('first.zip')), $sha1);
    }

    public function testExtractedManifestPassesManifestValidator(): void
    {
        $pluginDir = $this->createPluginFixture();
        $outZip = $this->tempPath('out.zip');

        (new PluginZipBuilder())->build($pluginDir, $outZip);

        $zip = new \ZipArchive();
        $zip->open($outZip);
        $json = $zip->getFromName('manifest.json');
        $zip->close();

        self::assertIsString($json);
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame([], (new ManifestValidator())->validate($data));
    }

    public function testMissingPluginDirectoryThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new PluginZipBuilder())->build($this->tempPath('does-not-exist'), $this->tempPath('out.zip'));
    }

    /**
     * @return list<string>
     */
    private static function listEntries(string $zipPath): array
    {
        $zip = new \ZipArchive();
        $zip->open($zipPath);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            \assert($name !== false);
            $names[] = $name;
        }
        $zip->close();

        return $names;
    }

    private function createPluginFixture(): string
    {
        $dir = $this->tempPath('vendor-name');
        mkdir($dir.'/src', 0o777, true);
        mkdir($dir.'/tests');
        mkdir($dir.'/vendor/some-dep', 0o777, true);
        mkdir($dir.'/.git');

        file_put_contents($dir.'/manifest.json', json_encode([
            'id' => 'vendor-name',
            'name' => 'Test plugin',
            'version' => '0.1.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => [
                'core' => '>=0.0.1',
                'php' => '>=8.1',
            ],
        ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

        file_put_contents(
            $dir.'/src/Widget.php',
            "<?php\n\nnamespace AnimeDb\\Plugins\\VendorName;\n\nfinal class Widget\n{\n}\n",
        );
        file_put_contents($dir.'/README.md', "# Test plugin\n");
        file_put_contents($dir.'/composer.lock', '{}');
        file_put_contents($dir.'/.gitignore', "/vendor/\n");
        file_put_contents($dir.'/.php-cs-fixer.dist.php', "<?php\n");
        file_put_contents($dir.'/tests/WidgetTest.php', "<?php\n");
        file_put_contents($dir.'/vendor/some-dep/Dep.php', "<?php\n");

        return $dir;
    }

    private function tempPath(string $name): string
    {
        if ($this->tempDirs === []) {
            $root = sys_get_temp_dir().'/plugin-zip-builder-test-'.bin2hex(random_bytes(8));
            mkdir($root);
            $this->tempDirs[] = $root;
        }

        return $this->tempDirs[0].'/'.$name;
    }

    private static function removeDirectory(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);

            return;
        }

        if (!is_dir($dir)) {
            unlink($dir);

            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeDirectory($dir.'/'.$entry);
        }

        rmdir($dir);
    }
}
