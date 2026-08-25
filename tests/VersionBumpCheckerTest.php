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

use AnimeDb\Plugins\Tools\VersionBumpChecker;
use PHPUnit\Framework\TestCase;

/**
 * Covers the edge-case table from issue #87: a plugin is skipped when it is new, removed,
 * or only its non-published files (tests/) changed, or when nothing under plugins/ changed
 * at all; it fails when published content changed without the version increasing, when the
 * version was lowered, or when the bumped-to version's release tag already exists.
 *
 * Fixtures live entirely in this test's own arrays — no real plugins/<id>/ directory is
 * touched, since PrChangeChecker's "one plugin / only its code" gate would otherwise reject
 * a PR that edits both this test and a real plugin.
 */
final class VersionBumpCheckerTest extends TestCase
{
    public function testNewPluginNotOnBaseBranchIsSkipped(): void
    {
        $result = (new VersionBumpChecker())->check(
            ['plugins/new-plugin/manifest.json', 'plugins/new-plugin/src/Widget.php'],
            new FakeManifestVersionSource(base: [], head: ['new-plugin' => '0.1.0']),
            new FakeTagExistenceChecker(),
        );

        self::assertTrue($result->isValid());
    }

    public function testPluginRemovedEntirelyIsSkipped(): void
    {
        $result = (new VersionBumpChecker())->check(
            ['plugins/removed-plugin/manifest.json', 'plugins/removed-plugin/src/Widget.php'],
            new FakeManifestVersionSource(base: ['removed-plugin' => '0.1.0'], head: []),
            new FakeTagExistenceChecker(),
        );

        self::assertTrue($result->isValid());
    }

    public function testOnlyTestsDirectoryChangedIsSkipped(): void
    {
        $result = (new VersionBumpChecker())->check(
            ['plugins/vendor-name/tests/WidgetTest.php'],
            new FakeManifestVersionSource(base: ['vendor-name' => '0.2.0'], head: ['vendor-name' => '0.2.0']),
            new FakeTagExistenceChecker(),
        );

        self::assertTrue($result->isValid());
    }

    public function testChangesOutsidePluginsDirectoryDoNotEngageTheGate(): void
    {
        $result = (new VersionBumpChecker())->check(
            ['README.md', 'tools/src/PluginValidator.php'],
            new FakeManifestVersionSource(base: ['vendor-name' => '0.2.0'], head: ['vendor-name' => '0.2.0']),
            new FakeTagExistenceChecker(),
        );

        self::assertTrue($result->isValid());
    }

    public function testContentChangedWithSameVersionFailsAndNamesTheFile(): void
    {
        $result = (new VersionBumpChecker())->check(
            ['plugins/vendor-name/src/Widget.php'],
            new FakeManifestVersionSource(base: ['vendor-name' => '0.2.0'], head: ['vendor-name' => '0.2.0']),
            new FakeTagExistenceChecker(),
        );

        self::assertFalse($result->isValid());
        self::assertCount(1, $result->violations);
        self::assertSame('vendor-name', $result->violations[0]->pluginId);
        self::assertStringContainsString('plugins/vendor-name/src/Widget.php', $result->violations[0]->message);
    }

    public function testVersionLoweredFails(): void
    {
        $result = (new VersionBumpChecker())->check(
            ['plugins/vendor-name/src/Widget.php'],
            new FakeManifestVersionSource(base: ['vendor-name' => '0.3.0'], head: ['vendor-name' => '0.2.0']),
            new FakeTagExistenceChecker(),
        );

        self::assertFalse($result->isValid());
        self::assertSame('vendor-name', $result->violations[0]->pluginId);
    }

    public function testExistingReleaseTagFails(): void
    {
        $result = (new VersionBumpChecker())->check(
            ['plugins/vendor-name/src/Widget.php'],
            new FakeManifestVersionSource(base: ['vendor-name' => '0.2.0'], head: ['vendor-name' => '0.3.0']),
            new FakeTagExistenceChecker(['vendor-name/0.3.0']),
        );

        self::assertFalse($result->isValid());
        self::assertStringContainsString('vendor-name/0.3.0', $result->violations[0]->message);
    }

    public function testBumpedVersionWithoutExistingTagPasses(): void
    {
        $result = (new VersionBumpChecker())->check(
            ['plugins/vendor-name/src/Widget.php'],
            new FakeManifestVersionSource(base: ['vendor-name' => '0.2.0'], head: ['vendor-name' => '0.3.0']),
            new FakeTagExistenceChecker(),
        );

        self::assertTrue($result->isValid());
    }

    public function testTagExistenceCheckFailureFailsClosedWithAViolation(): void
    {
        $result = (new VersionBumpChecker())->check(
            ['plugins/vendor-name/src/Widget.php'],
            new FakeManifestVersionSource(base: ['vendor-name' => '0.2.0'], head: ['vendor-name' => '0.3.0']),
            new FakeTagExistenceChecker(failingChecks: ['vendor-name/0.3.0']),
        );

        self::assertFalse($result->isValid());
        self::assertCount(1, $result->violations);
        self::assertSame('vendor-name', $result->violations[0]->pluginId);
        self::assertStringContainsString('vendor-name/0.3.0', $result->violations[0]->message);
        self::assertStringContainsString('simulated check failure', $result->violations[0]->message);
    }

    public function testOnlyExcludedDevArtifactsChangedIsSkippedEvenWithSameVersion(): void
    {
        $result = (new VersionBumpChecker())->check(
            [
                'plugins/vendor-name/composer.lock',
                'plugins/vendor-name/.php-cs-fixer.cache',
                'plugins/vendor-name/vendor/some-dep/Dep.php',
            ],
            new FakeManifestVersionSource(base: ['vendor-name' => '0.2.0'], head: ['vendor-name' => '0.2.0']),
            new FakeTagExistenceChecker(),
        );

        self::assertTrue($result->isValid());
    }
}
