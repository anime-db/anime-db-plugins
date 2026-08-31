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

use AnimeDb\Plugins\Tools\PluginRegistryBuilder;
use PHPUnit\Framework\TestCase;

final class PluginRegistryBuilderTest extends TestCase
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

    public function testSinglePluginWithSingleVersion(): void
    {
        $pluginsDir = $this->createPluginsFixture([
            'widget-plugin' => ['version' => '0.1.0', 'name' => 'Widget'],
        ]);

        $result = (new PluginRegistryBuilder())->build($pluginsDir, [
            ['id' => 'widget-plugin', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'abc123'],
        ], 1);

        self::assertSame(1, $result['sequence']);
        self::assertSame(
            ['https://github.com/anime-db/anime-db-plugins/releases/download/<id>/<version>/<file>'],
            $result['asset_mirrors'],
        );
        self::assertCount(1, $result['plugins']);

        $plugin = $result['plugins'][0];
        self::assertSame('widget-plugin', $plugin['id']);
        self::assertSame('Widget', $plugin['manifest']['name']);
        self::assertSame('0.1.0', $plugin['manifest']['version']);
        self::assertSame(
            [['version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'abc123']],
            $plugin['versions'],
        );
    }

    public function testMultipleVersionsAreSortedNewestFirstAndManifestIsTheRepoOne(): void
    {
        $pluginsDir = $this->createPluginsFixture([
            'widget-plugin' => ['version' => '0.3.0', 'name' => 'Widget'],
        ]);

        $result = (new PluginRegistryBuilder())->build($pluginsDir, [
            ['id' => 'widget-plugin', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'sha-0.1.0'],
            ['id' => 'widget-plugin', 'version' => '0.3.0', 'core' => '>=0.2.0', 'sha256' => 'sha-0.3.0'],
            ['id' => 'widget-plugin', 'version' => '0.2.0', 'core' => '>=0.1.0', 'sha256' => 'sha-0.2.0'],
        ], 1);

        $plugin = $result['plugins'][0];

        // manifest embedded is always the current repo one (the latest), regardless of how
        // many older published versions exist.
        self::assertSame('0.3.0', $plugin['manifest']['version']);

        self::assertSame(
            ['0.3.0', '0.2.0', '0.1.0'],
            array_column($plugin['versions'], 'version'),
        );
        self::assertSame(
            [
                ['version' => '0.3.0', 'core' => '>=0.2.0', 'sha256' => 'sha-0.3.0'],
                ['version' => '0.2.0', 'core' => '>=0.1.0', 'sha256' => 'sha-0.2.0'],
                ['version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'sha-0.1.0'],
            ],
            $plugin['versions'],
        );
    }

    public function testMultiplePluginsAreSortedAlphabeticallyById(): void
    {
        $pluginsDir = $this->createPluginsFixture([
            'zeta-plugin' => ['version' => '0.1.0', 'name' => 'Zeta'],
            'alpha-plugin' => ['version' => '0.1.0', 'name' => 'Alpha'],
        ]);

        $result = (new PluginRegistryBuilder())->build($pluginsDir, [
            ['id' => 'zeta-plugin', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'sha-zeta'],
            ['id' => 'alpha-plugin', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'sha-alpha'],
        ], 1);

        self::assertSame(
            ['alpha-plugin', 'zeta-plugin'],
            array_column($result['plugins'], 'id'),
        );
    }

    public function testOutputIsDeterministicAcrossTwoRunsOfTheSameInput(): void
    {
        $pluginsDir = $this->createPluginsFixture([
            'widget-plugin' => ['version' => '0.2.0', 'name' => 'Widget'],
            'gadget-plugin' => ['version' => '0.1.0', 'name' => 'Gadget'],
        ]);
        $publishedVersions = [
            ['id' => 'widget-plugin', 'version' => '0.2.0', 'core' => '>=0.1.0', 'sha256' => 'sha-widget-2'],
            ['id' => 'widget-plugin', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'sha-widget-1'],
            ['id' => 'gadget-plugin', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'sha-gadget-1'],
        ];

        $builder = new PluginRegistryBuilder();
        $first = json_encode($builder->build($pluginsDir, $publishedVersions, 1), \JSON_THROW_ON_ERROR);
        $second = json_encode($builder->build($pluginsDir, $publishedVersions, 1), \JSON_THROW_ON_ERROR);

        self::assertSame($first, $second);
    }

    public function testPluginWithoutPublishedVersionsIsOmitted(): void
    {
        $pluginsDir = $this->createPluginsFixture([
            'widget-plugin' => ['version' => '0.1.0', 'name' => 'Widget'],
            'unpublished-plugin' => ['version' => '0.1.0', 'name' => 'Unpublished'],
        ]);

        $result = (new PluginRegistryBuilder())->build($pluginsDir, [
            ['id' => 'widget-plugin', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'abc123'],
        ], 1);

        self::assertSame(['widget-plugin'], array_column($result['plugins'], 'id'));
    }

    public function testMissingPluginsDirectoryThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new PluginRegistryBuilder())->build($this->tempPath('does-not-exist'), [], 1);
    }

    public function testPublishedVersionReferencingUnknownPluginThrows(): void
    {
        $pluginsDir = $this->createPluginsFixture([]);

        $this->expectException(\RuntimeException::class);

        (new PluginRegistryBuilder())->build($pluginsDir, [
            ['id' => 'ghost-plugin', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'abc123'],
        ], 1);
    }

    public function testPathTraversalPluginIdThrowsInsteadOfEscapingThePluginsDirectory(): void
    {
        $root = \dirname($this->tempPath('plugins'));
        $pluginsDir = $root.'/plugins';
        mkdir($pluginsDir, 0o777, true);

        // A manifest.json placed just outside the plugins directory, reachable via traversal.
        file_put_contents(
            $root.'/manifest.json',
            json_encode(['id' => 'secret-plugin', 'name' => 'Secret', 'version' => '0.1.0'], \JSON_THROW_ON_ERROR),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a valid plugin id/');

        (new PluginRegistryBuilder())->build($pluginsDir, [
            ['id' => '..', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'abc123'],
        ], 1);
    }

    public function testNonPositiveSequenceThrows(): void
    {
        $pluginsDir = $this->createPluginsFixture([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/positive integer/');

        (new PluginRegistryBuilder())->build($pluginsDir, [], 0);
    }

    public function testCodelessTranslationPluginIsBuiltLikeAnyOtherPlugin(): void
    {
        $pluginsDir = $this->tempPath('plugins');
        mkdir($pluginsDir.'/vendor-name', 0o777, true);
        file_put_contents(
            $pluginsDir.'/vendor-name/manifest.json',
            json_encode([
                'id' => 'vendor-name',
                'name' => 'Test translation plugin',
                'version' => '0.1.0',
                'type' => 'translation',
                'locales' => ['en'],
                'require' => [
                    'core' => '>=0.0.1',
                    'php' => '>=8.1',
                ],
            ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        $result = (new PluginRegistryBuilder())->build($pluginsDir, [
            ['id' => 'vendor-name', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'abc123'],
        ], 1);

        self::assertSame(['vendor-name'], array_column($result['plugins'], 'id'));
        self::assertSame('translation', $result['plugins'][0]['manifest']['type']);
    }

    public function testTranslationKeysCountIsPreservedWhenPresentAndOmittedWhenAbsent(): void
    {
        $pluginsDir = $this->createPluginsFixture([
            'vendor-language-pack' => ['version' => '0.2.0', 'name' => 'Language pack'],
        ]);

        $result = (new PluginRegistryBuilder())->build($pluginsDir, [
            // Older version, published before translation_keys_count existed: no such
            // field in the release asset's manifest.json, so none in the entry either.
            ['id' => 'vendor-language-pack', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'sha-0.1.0'],
            ['id' => 'vendor-language-pack', 'version' => '0.2.0', 'core' => '>=0.1.0', 'sha256' => 'sha-0.2.0', 'translation_keys_count' => 358],
        ], 1);

        $versions = $result['plugins'][0]['versions'];

        self::assertSame('0.2.0', $versions[0]['version']);
        self::assertSame(358, $versions[0]['translation_keys_count']);

        self::assertSame('0.1.0', $versions[1]['version']);
        self::assertArrayNotHasKey('translation_keys_count', $versions[1]);
    }

    public function testLocalesIsPreservedWhenPresentAndOmittedWhenAbsent(): void
    {
        $pluginsDir = $this->createPluginsFixture([
            'vendor-language-pack' => ['version' => '0.2.0', 'name' => 'Language pack'],
        ]);

        $result = (new PluginRegistryBuilder())->build($pluginsDir, [
            // Older version, published before "locales" was carried into the registry: no such
            // field in the release asset's manifest.json, so none in the entry either.
            ['id' => 'vendor-language-pack', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'sha-0.1.0'],
            ['id' => 'vendor-language-pack', 'version' => '0.2.0', 'core' => '>=0.1.0', 'sha256' => 'sha-0.2.0', 'locales' => ['de', 'ja']],
        ], 1);

        $versions = $result['plugins'][0]['versions'];

        self::assertSame('0.2.0', $versions[0]['version']);
        self::assertSame(['de', 'ja'], $versions[0]['locales']);

        self::assertSame('0.1.0', $versions[1]['version']);
        self::assertArrayNotHasKey('locales', $versions[1]);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function provideInvalidLocales(): iterable
    {
        yield 'string' => ['en'];
        yield 'list of non-strings' => [[1, 2]];
        yield 'empty list' => [[]];
        yield 'list containing an empty string' => [['en', '']];
        yield 'non-list array' => [['a' => 'en']];
        yield 'null' => [null];
    }

    /**
     * @dataProvider provideInvalidLocales
     */
    public function testInvalidLocalesTypeIsRejectedWithAClearMessage(mixed $invalidLocales): void
    {
        $pluginsDir = $this->createPluginsFixture([
            'vendor-language-pack' => ['version' => '0.1.0', 'name' => 'Language pack'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/"locales" must be a non-empty list of strings/');

        (new PluginRegistryBuilder())->build($pluginsDir, [
            ['id' => 'vendor-language-pack', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'sha-0.1.0', 'locales' => $invalidLocales],
        ], 1);
    }

    public function testPluginContractsIsPreservedWhenPresentAndOmittedWhenAbsent(): void
    {
        $pluginsDir = $this->createPluginsFixture([
            'vendor-integration' => ['version' => '0.2.0', 'name' => 'Integration'],
        ]);

        $result = (new PluginRegistryBuilder())->build($pluginsDir, [
            // Older version, published before plugin_contracts was carried into the registry:
            // no such field in the release asset's manifest.json, so none in the entry either.
            ['id' => 'vendor-integration', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'sha-0.1.0'],
            ['id' => 'vendor-integration', 'version' => '0.2.0', 'core' => '>=0.0.1', 'sha256' => 'sha-0.2.0', 'plugin_contracts' => '^0.15'],
        ], 1);

        $versions = $result['plugins'][0]['versions'];

        self::assertSame('0.2.0', $versions[0]['version']);
        self::assertSame('^0.15', $versions[0]['plugin_contracts']);

        self::assertSame('0.1.0', $versions[1]['version']);
        self::assertArrayNotHasKey('plugin_contracts', $versions[1]);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function provideInvalidPluginContracts(): iterable
    {
        yield 'non-string (int)' => [10];
        yield 'non-string (list)' => [['^0.10']];
        yield 'empty string' => [''];
        yield 'unparsable constraint' => ['not-a-constraint'];
    }

    /**
     * @dataProvider provideInvalidPluginContracts
     */
    public function testInvalidPluginContractsIsRejectedWithAClearMessageNamingThePluginAndVersion(mixed $invalidPluginContracts): void
    {
        $pluginsDir = $this->createPluginsFixture([
            'vendor-integration' => ['version' => '0.1.0', 'name' => 'Integration'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/"vendor-integration" version "0\.1\.0": "plugin_contracts"/');

        (new PluginRegistryBuilder())->build($pluginsDir, [
            ['id' => 'vendor-integration', 'version' => '0.1.0', 'core' => '>=0.0.1', 'sha256' => 'sha-0.1.0', 'plugin_contracts' => $invalidPluginContracts],
        ], 1);
    }

    public function testExplicitAssetMirrorsOverrideTheGithubOnlyDefault(): void
    {
        $pluginsDir = $this->createPluginsFixture([]);

        $result = (new PluginRegistryBuilder())->build($pluginsDir, [], 1, [
            'https://github.com/anime-db/anime-db-plugins/releases/download/<id>/<version>/<file>',
            'https://mirror1.example.org/mirror/<id>/<version>/<file>',
        ]);

        self::assertSame(
            [
                'https://github.com/anime-db/anime-db-plugins/releases/download/<id>/<version>/<file>',
                'https://mirror1.example.org/mirror/<id>/<version>/<file>',
            ],
            $result['asset_mirrors'],
        );
    }

    /**
     * @param array<string, array{version: string, name: string}> $plugins
     */
    private function createPluginsFixture(array $plugins): string
    {
        $pluginsDir = $this->tempPath('plugins');
        mkdir($pluginsDir, 0o777, true);

        foreach ($plugins as $id => $data) {
            mkdir($pluginsDir.'/'.$id, 0o777, true);
            file_put_contents(
                $pluginsDir.'/'.$id.'/manifest.json',
                json_encode([
                    'id' => $id,
                    'name' => $data['name'],
                    'version' => $data['version'],
                    'type' => 'integration',
                    'require' => [
                        'core' => '>=0.0.1',
                        'php' => '>=8.1',
                    ],
                ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
            );
        }

        return $pluginsDir;
    }

    private function tempPath(string $name): string
    {
        if ($this->tempDirs === []) {
            $root = sys_get_temp_dir().'/plugin-registry-builder-test-'.bin2hex(random_bytes(8));
            mkdir($root);
            $this->tempDirs[] = $root;
        }

        return $this->tempDirs[0].'/'.$name;
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
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
