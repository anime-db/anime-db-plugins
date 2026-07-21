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

use AnimeDb\Plugins\Tools\PluginValidator;
use PHPUnit\Framework\TestCase;

final class PluginValidatorTest extends TestCase
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

    public function testRealShikimoriPluginIsValid(): void
    {
        $pluginDir = \dirname(__DIR__).'/plugins/animedb-shikimori';

        self::assertSame([], (new PluginValidator())->validate($pluginDir));
    }

    public function testMissingManifestIsReported(): void
    {
        $pluginDir = $this->createPluginDir('missing-manifest', null);

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertContains('manifest.json is missing in the plugin root.', $errors);
    }

    public function testManifestMissingRequiredFieldIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        unset($manifest['name']);
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'name'));
    }

    public function testManifestInvalidIdIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $manifest['id'] = 'Not A Valid Id!';
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'not a valid id'));
    }

    public function testManifestInvalidVersionIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $manifest['version'] = 'not-a-version';
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'not a valid version'));
    }

    public function testManifestInvalidRequireCoreIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $manifest['require']['core'] = '^1.0';
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'require.core'));
    }

    public function testIdNotMatchingDirectoryNameIsReported(): void
    {
        $manifest = $this->validManifest('other-id');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'does not match plugin directory name'));
    }

    public function testWrongNamespaceIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        file_put_contents($pluginDir.'/src/Widget.php', "<?php\n\nnamespace Totally\\Wrong\\Namespace;\n\nfinal class Widget\n{\n}\n");

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'declares namespace "Totally\\Wrong\\Namespace"'));
    }

    public function testMissingSrcDirectoryIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest, withSrc: false);

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertContains('Plugin is missing a "src/" directory.', $errors);
    }

    public function testSyntaxErrorIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        file_put_contents($pluginDir.'/src/Broken.php', "<?php\n\nnamespace AnimeDb\\Plugins\\VendorName;\n\nfinal class Broken {\n");

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'Syntax error'));
    }

    public function testValidPluginHasNoErrors(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);

        self::assertSame([], (new PluginValidator())->validate($pluginDir));
    }

    /**
     * @return array<string, mixed>
     */
    private function validManifest(string $id): array
    {
        return [
            'id' => $id,
            'name' => 'Test plugin',
            'version' => '0.1.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => [
                'core' => '>=0.0.1',
                'php' => '>=8.1',
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $manifest
     */
    private function createPluginDir(string $dirName, ?array $manifest, bool $withSrc = true): string
    {
        $dir = sys_get_temp_dir().'/plugin-validator-test-'.bin2hex(random_bytes(8)).'/'.$dirName;
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = \dirname($dir);

        if (null !== $manifest) {
            file_put_contents($dir.'/manifest.json', json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        }

        if ($withSrc) {
            mkdir($dir.'/src');
            $studly = str_replace('-', '', ucwords($dirName, '-'));
            file_put_contents(
                $dir.'/src/Widget.php',
                "<?php\n\nnamespace AnimeDb\\Plugins\\{$studly};\n\nfinal class Widget\n{\n}\n",
            );
        }

        return $dir;
    }

    /**
     * @param list<string> $errors
     */
    private static function hasErrorContaining(array $errors, string $needle): bool
    {
        foreach ($errors as $error) {
            if (str_contains($error, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
