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

    public function testReservedVendorIdIsRejected(): void
    {
        $manifest = $this->validManifest('animedb-fake');
        $pluginDir = $this->createPluginDir('animedb-fake', $manifest);

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'reserved "animedb" vendor'));
    }

    public function testOfficialPluginIdIsAllowedToUseReservedVendor(): void
    {
        $manifest = $this->validManifest('animedb-shikimori');
        $pluginDir = $this->createPluginDir('animedb-shikimori', $manifest);

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertFalse(self::hasErrorContaining($errors, 'reserved "animedb" vendor'));
    }

    public function testSymlinkToFileOutsidePluginIsNotRead(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);

        $outsideFile = \dirname($pluginDir).'/outside.php';
        file_put_contents($outsideFile, "<?php\n\nsyntax error not php at all {{{\n");
        symlink($outsideFile, $pluginDir.'/src/Escape.php');

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertSame([], $errors);
    }

    public function testSymlinkToDirectoryIsNotFollowed(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);

        $outsideDir = \dirname($pluginDir).'/outside-dir';
        mkdir($outsideDir);
        file_put_contents($outsideDir.'/Bad.php', "<?php broken {{{\n");
        symlink($outsideDir, $pluginDir.'/src/EscapeDir');

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertSame([], $errors);
    }

    public function testSymlinkCycleDoesNotCauseInfiniteRecursion(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);

        symlink($pluginDir.'/src', $pluginDir.'/src/cycle');

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertSame([], $errors);
    }

    public function testSymlinkedPluginDirectoryIsRejected(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $realDir = $this->createPluginDir('vendor-name', $manifest);

        // $linkDir lives inside the same temp root as $realDir (tracked by createPluginDir()
        // above), so it's already covered by tearDown()'s cleanup.
        $linkDir = \dirname($realDir).'/vendor-name-link';
        symlink($realDir, $linkDir);

        $errors = (new PluginValidator())->validate($linkDir);

        self::assertTrue(self::hasErrorContaining($errors, 'must not be a symlink'));
    }

    public function testSymlinkedSrcDirectoryIsRejected(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest, withSrc: false);

        $outsideSrc = \dirname($pluginDir).'/outside-src';
        mkdir($outsideSrc);
        file_put_contents(
            $outsideSrc.'/Widget.php',
            "<?php\n\nnamespace AnimeDb\\Plugins\\VendorName;\n\nfinal class Widget\n{\n}\n",
        );
        symlink($outsideSrc, $pluginDir.'/src');

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, '"src/" must not be a symlink'));
    }

    public function testMatchingTranslationKeysHaveNoErrors(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'en', "greeting: Hello\nfarewell: Bye\n");
        $this->writeTranslation($pluginDir, 'ru', "greeting: Привет\nfarewell: Пока\n");

        self::assertSame([], (new PluginValidator())->validate($pluginDir));
    }

    public function testTranslationKeyOnlyInOneLocaleIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'en', "greeting: Hello\nfarewell: Bye\n");
        $this->writeTranslation($pluginDir, 'ru', "greeting: Привет\n");

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'farewell'));
    }

    public function testNestedTranslationKeysAreComparedCorrectly(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'en', "widget:\n    title: Hello\n    hint: Hint\n");
        $this->writeTranslation($pluginDir, 'ru', "widget:\n    title: Привет\n");

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'widget.hint'));
    }

    public function testMissingTranslationsDirectoryHasNoErrors(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);

        self::assertSame([], (new PluginValidator())->validate($pluginDir));
    }

    public function testSingleLocaleTranslationHasNoErrors(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'en', "greeting: Hello\n");

        self::assertSame([], (new PluginValidator())->validate($pluginDir));
    }

    public function testTranslationFileWithWrongDomainIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        mkdir($pluginDir.'/translations');
        file_put_contents($pluginDir.'/translations/other-domain.en.yaml', "greeting: Hello\n");

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'uses domain "other-domain" instead of "vendor-name"'));
    }

    public function testTranslationPluginWithMessagesDomainHasNoErrors(): void
    {
        $manifest = $this->validManifest('vendor-name', 'translation');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'de', "greeting: Hallo\n", 'messages');

        self::assertSame([], (new PluginValidator())->validate($pluginDir));
    }

    public function testTranslationPluginWithOwnIdDomainIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name', 'translation');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'de', "greeting: Hallo\n");

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'uses domain "vendor-name" instead of "messages"'));
    }

    public function testIntegrationPluginWithMessagesDomainIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'ru', "greeting: Привет\n", 'messages');

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'uses domain "messages" instead of "vendor-name"'));
    }

    public function testIntegrationPluginWithOwnIdDomainHasNoErrors(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'ru', "greeting: Привет\n");

        self::assertSame([], (new PluginValidator())->validate($pluginDir));
    }

    public function testTranslationKeyParityIsCheckedEvenWhenManifestIsMissing(): void
    {
        $pluginDir = $this->createPluginDir('vendor-name', null);
        $this->writeTranslation($pluginDir, 'en', "greeting: Hello\nfarewell: Bye\n");
        $this->writeTranslation($pluginDir, 'ru', "greeting: Привет\n");

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'farewell'));
    }

    public function testSymlinkedTranslationsDirectoryIsRejected(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);

        $outsideDir = \dirname($pluginDir).'/outside-translations';
        mkdir($outsideDir);
        file_put_contents($outsideDir.'/vendor-name.en.yaml', "greeting: Hello\n");
        symlink($outsideDir, $pluginDir.'/translations');

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, '"translations/" must not be a symlink'));
    }

    public function testSymlinkedTranslationCatalogIsRejected(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        mkdir($pluginDir.'/translations');

        $outsideFile = \dirname($pluginDir).'/outside.yaml';
        file_put_contents($outsideFile, "greeting: Hello\n");
        symlink($outsideFile, $pluginDir.'/translations/vendor-name.en.yaml');

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'Translation catalog "translations/vendor-name.en.yaml" must not be a symlink'));
    }

    public function testMatchingPlaceholdersHaveNoErrors(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'en', "greeting: 'Hello, %name%!'\n");
        $this->writeTranslation($pluginDir, 'ru', "greeting: 'Привет, %name%!'\n");

        self::assertSame([], (new PluginValidator())->validate($pluginDir));
    }

    public function testMissingPlaceholderIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'en', "error: 'Failed: %detail%'\n");
        $this->writeTranslation($pluginDir, 'ru', "error: 'Ошибка'\n");

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'key "error"'));
        self::assertTrue(self::hasErrorContaining($errors, 'detail'));
    }

    public function testRenamedPlaceholderIsReportedInBothDirections(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'en', "error: 'Failed: %detail%'\n");
        $this->writeTranslation($pluginDir, 'ru', "error: 'Ошибка: %reason%'\n");

        $errors = (new PluginValidator())->validate($pluginDir);

        $error = self::findErrorContaining($errors, 'key "error"');
        self::assertNotNull($error);
        self::assertStringContainsString('only in "en": detail', $error);
        self::assertStringContainsString('only in "ru": reason', $error);
    }

    public function testDifferentPlaceholderOrderWithSameSetHasNoErrors(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'en', "greeting: '%greeting%, %name%!'\n");
        $this->writeTranslation($pluginDir, 'ru', "greeting: '%name%, %greeting%!'\n");

        self::assertSame([], (new PluginValidator())->validate($pluginDir));
    }

    public function testDuplicatedPlaceholderCountMismatchIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'en', "greeting: '%name%, %name%!'\n");
        $this->writeTranslation($pluginDir, 'ru', "greeting: '%name%!'\n");

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'key "greeting"'));
    }

    public function testCurlyBraceValueIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        $this->writeTranslation($pluginDir, 'en', "greeting: 'Hello, {name}!'\n");

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'key "greeting"'));
        self::assertTrue(self::hasErrorContaining($errors, '"{" or "}"'));
    }

    public function testUnsupportedTranslationFileFormatIsReported(): void
    {
        $manifest = $this->validManifest('vendor-name');
        $pluginDir = $this->createPluginDir('vendor-name', $manifest);
        mkdir($pluginDir.'/translations');
        file_put_contents($pluginDir.'/translations/vendor-name.en.yml', "greeting: Hello\n");

        $errors = (new PluginValidator())->validate($pluginDir);

        self::assertTrue(self::hasErrorContaining($errors, 'unsupported format'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validManifest(string $id, string $type = 'integration'): array
    {
        $manifest = [
            'id' => $id,
            'name' => 'Test plugin',
            'version' => '0.1.0',
            'type' => $type,
            'require' => [
                'core' => '>=0.0.1',
                'php' => '>=8.1',
            ],
        ];

        return $type === 'translation'
            ? [...$manifest, 'locales' => ['en', 'ru']]
            : [...$manifest, 'features' => ['filler' => true]];
    }

    /**
     * @param array<string, mixed>|null $manifest
     */
    private function createPluginDir(string $dirName, ?array $manifest, bool $withSrc = true): string
    {
        $dir = sys_get_temp_dir().'/plugin-validator-test-'.bin2hex(random_bytes(8)).'/'.$dirName;
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = \dirname($dir);

        if ($manifest !== null) {
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

    private function writeTranslation(string $pluginDir, string $locale, string $yaml, ?string $domain = null): void
    {
        $translationsDir = $pluginDir.'/translations';
        if (!is_dir($translationsDir)) {
            mkdir($translationsDir);
        }

        $domain ??= basename($pluginDir);
        file_put_contents($translationsDir.'/'.$domain.'.'.$locale.'.yaml', $yaml);
    }

    /**
     * @param list<string> $errors
     */
    private static function hasErrorContaining(array $errors, string $needle): bool
    {
        return self::findErrorContaining($errors, $needle) !== null;
    }

    /**
     * @param list<string> $errors
     */
    private static function findErrorContaining(array $errors, string $needle): ?string
    {
        foreach ($errors as $error) {
            if (str_contains($error, $needle)) {
                return $error;
            }
        }

        return null;
    }

    private static function removeDirectory(string $dir): void
    {
        // is_link() is checked before is_dir(), which follows symlinks: some fixtures
        // intentionally contain symlinks (including a cyclic one) to exercise
        // PluginValidator's own handling of them, and must not be followed here either.
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
