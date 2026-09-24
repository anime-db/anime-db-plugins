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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the plugin test runner (issue #147).
 *
 * The runner exists because a plugin's own suite was executed by nothing: the root
 * phpunit.xml.dist has a single testsuite, `tests`, and `plugins/<id>/tests/` is outside it.
 * A runner that reports green without having run anything reproduces that same failure one
 * level up, so these tests assert the two directions separately — a failing plugin test must
 * come back red, and a plugin with no tests must come back green *and say so*. An
 * exit-code-only test would accept a runner that never found the suite.
 *
 * The second half checks the wiring, for the same reason AnalysePluginCliTest does: a gate
 * nobody invokes is worth as little as a gate that finds nothing.
 */
final class TestPluginCliTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->temporaryDirectories as $directory) {
            exec('rm -rf '.escapeshellarg($directory));
        }

        $this->temporaryDirectories = [];
    }

    public function testPassingSuiteExitsZero(): void
    {
        $plugin = $this->createPlugin('passing', <<<'PHP'
            public function testItPasses(): void
            {
                self::assertSame(2, 1 + 1);
            }
            PHP);

        [$exitCode, $output] = $this->invoke($plugin);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('OK (1 test', $output);
    }

    /**
     * The failing direction, and the one that actually matters: before this runner existed a
     * broken plugin test could not turn CI red, because nothing evaluated it.
     */
    public function testFailingSuiteExitsNonZero(): void
    {
        $plugin = $this->createPlugin('failing', <<<'PHP'
            public function testItFails(): void
            {
                self::assertSame(3, 1 + 1);
            }
            PHP);

        [$exitCode, $output] = $this->invoke($plugin);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('FAILURES', $output);
    }

    /**
     * The plugin's own classes must be loadable from its test, and via the PSR-4 map declared
     * in its composer.json — not by accident of the working directory or of some include path.
     */
    public function testPluginSourceIsAutoloadedFromItsComposerPsr4Map(): void
    {
        $plugin = $this->createPlugin('autoload', <<<'PHP'
            public function testItSeesPluginCode(): void
            {
                self::assertSame('ok', \AnimeDb\Plugins\Probe\Subject::answer());
            }
            PHP);

        file_put_contents($plugin.'/src/Subject.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace AnimeDb\Plugins\Probe;

            final class Subject
            {
                public static function answer(): string
                {
                    return 'ok';
                }
            }
            PHP);

        [$exitCode, $output] = $this->invoke($plugin);

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * `autoload-dev` has to be registered too, and its absence does not show up on an ordinary
     * test class: PHPUnit is handed the test directory, so it loads every `*Test.php` by path
     * and the autoloader is never consulted for them. What needs it is a helper class living
     * in `tests/` beside them — this repository's own suite is full of those (`Fake*`), and a
     * runner that dropped `autoload-dev` would stay green here and fail only once a plugin
     * shipped one.
     */
    public function testTestHelperBesideTheSuiteIsAutoloadedFromComposerAutoloadDev(): void
    {
        $plugin = $this->createPlugin('autoload-dev', <<<'PHP'
            public function testItSeesItsOwnHelper(): void
            {
                self::assertSame('helped', \AnimeDb\Plugins\Probe\Tests\FakeHelper::value());
            }
            PHP);

        // Deliberately not named *Test.php: PHPUnit must not pick it up by path, so the only
        // way the test above can see it is the PSR-4 map from autoload-dev.
        file_put_contents($plugin.'/tests/FakeHelper.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace AnimeDb\Plugins\Probe\Tests;

            final class FakeHelper
            {
                public static function value(): string
                {
                    return 'helped';
                }
            }
            PHP);

        [$exitCode, $output] = $this->invoke($plugin);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('OK (1 test', $output);
    }

    /**
     * A plugin of type `translation` ships no PHP at all, and a plugin may arrive before its
     * suite does. Green, but it has to say why — "green because nothing ran" and "green
     * because it ran" are indistinguishable from an exit code alone, which is the confusion
     * this whole runner exists to remove.
     */
    public function testPluginWithoutTestsDirectorySkipsGreen(): void
    {
        $plugin = $this->createPlugin('no-tests', null);
        exec('rm -rf '.escapeshellarg($plugin.'/tests'));

        [$exitCode, $output] = $this->invoke($plugin);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('SKIP', $output);
        self::assertStringContainsString('no tests/ directory', $output);
    }

    public function testPluginWithEmptyTestsDirectorySkipsGreen(): void
    {
        $plugin = $this->createPlugin('empty-tests', null);

        [$exitCode, $output] = $this->invoke($plugin);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('SKIP', $output);
        self::assertStringContainsString('no *Test.php files', $output);
    }

    /**
     * Symlinks are refused, not followed — and refused at depth, not only at the top level.
     * The distinction is the whole point: a filter that drops links from the walk also hides
     * them from a loop that inspects the yielded entries, so a nested link reads as a clean
     * tree. Here that would mean executing a file the gate never looked at.
     */
    public function testNestedSymlinkUnderTestsIsRefused(): void
    {
        $plugin = $this->createPlugin('symlink-nested', <<<'PHP'
            public function testItPasses(): void
            {
                self::assertTrue(true);
            }
            PHP);

        mkdir($plugin.'/tests/Nested');
        symlink('/etc/hostname', $plugin.'/tests/Nested/SmuggledTest.php');

        [$exitCode, $output] = $this->invoke($plugin);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('symlink', $output);
    }

    public function testTopLevelSymlinkUnderTestsIsRefused(): void
    {
        $plugin = $this->createPlugin('symlink-top', <<<'PHP'
            public function testItPasses(): void
            {
                self::assertTrue(true);
            }
            PHP);

        symlink('/etc', $plugin.'/tests/link');

        [$exitCode, $output] = $this->invoke($plugin);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('symlink', $output);
    }

    public function testMissingPluginDirExitsNonZero(): void
    {
        [$exitCode, $output] = $this->invoke(self::repoRoot().'/plugins/does-not-exist');

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('does not exist', $output);
    }

    public function testPrValidationWorkflowRunsThePluginSuiteAfterThePluginBoundaryGate(): void
    {
        $workflow = Yaml::parseFile(self::repoRoot().'/.github/workflows/pr-validation.yml');
        self::assertIsArray($workflow);

        $steps = $workflow['jobs']['validate']['steps'] ?? null;
        self::assertIsArray($steps, 'Job "validate" of pr-validation.yml has no steps.');

        $boundaryGateIndex = null;
        $pluginTestsIndex = null;

        foreach ($steps as $index => $step) {
            $run = \is_array($step) && \is_string($step['run'] ?? null) ? $step['run'] : '';

            if (str_contains($run, 'tools/check-pr-changes.php')) {
                $boundaryGateIndex = $index;
            }

            if (!str_contains($run, 'tools/test-plugin.php')) {
                continue;
            }

            $pluginTestsIndex = $index;

            $condition = $step['if'] ?? null;
            self::assertIsString($condition, 'The plugin test step must be conditional.');

            // On an infrastructural PR there is no affected plugin, and affected-plugin.txt
            // does not exist at all.
            self::assertStringContainsString(
                "steps.diff.outputs.touches_plugins == 'true'",
                $condition,
                'The plugin test step must be skipped on purely infrastructural PRs.',
            );

            // Gates the step on everything before it having passed — including the boundary
            // gate. `!cancelled()` alone cancels the implicit success(), which would let this
            // step execute the PR's own test code after the gate that vets it had failed.
            self::assertStringContainsString(
                "steps.tests.outcome != 'skipped'",
                $condition,
                'The plugin test step must not run once an earlier gate has failed.',
            );

            // Without this the step would run forever against a hardcoded plugin and stay
            // green while the plugin the PR actually touches went untested.
            self::assertStringContainsString(
                'affected-plugin.txt',
                $run,
                'The plugin suite must run against the plugin the PR touches, not a fixed one.',
            );
        }

        self::assertIsInt($boundaryGateIndex, 'pr-validation.yml no longer runs check-pr-changes.php.');
        self::assertIsInt($pluginTestsIndex, 'pr-validation.yml does not run the plugin test suite at all.');
        self::assertGreaterThan(
            $boundaryGateIndex,
            $pluginTestsIndex,
            'The plugin suite executes test code from the PR, so it must run after the boundary gate.',
        );
    }

    /**
     * A minimal plugin directory: composer.json with both PSR-4 maps, `src/`, and a `tests/`
     * holding one test class built around $body (null leaves tests/ empty).
     */
    private function createPlugin(string $name, ?string $body): string
    {
        $directory = sys_get_temp_dir().'/animedb-test-plugin-'.$name.'-'.bin2hex(random_bytes(6));
        $this->temporaryDirectories[] = $directory;

        mkdir($directory.'/src', 0o777, true);
        mkdir($directory.'/tests', 0o777, true);

        file_put_contents($directory.'/composer.json', json_encode([
            'name' => 'anime-db/probe-'.$name,
            'type' => 'anime-db-plugin',
            'autoload' => ['psr-4' => ['AnimeDb\\Plugins\\Probe\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['AnimeDb\\Plugins\\Probe\\Tests\\' => 'tests/']],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        if ($body === null) {
            return $directory;
        }

        file_put_contents($directory.'/tests/ProbeTest.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace AnimeDb\\Plugins\\Probe\\Tests;

            use PHPUnit\\Framework\\TestCase;

            final class ProbeTest extends TestCase
            {
            {$body}
            }
            PHP);

        return $directory;
    }

    /** @return array{0: int, 1: string} */
    private function invoke(string $pluginDir): array
    {
        exec(
            escapeshellarg(\PHP_BINARY).' '.escapeshellarg(self::repoRoot().'/tools/test-plugin.php').' '
                .escapeshellarg($pluginDir).' 2>&1',
            $output,
            $exitCode,
        );

        return [$exitCode, implode("\n", $output)];
    }

    private static function repoRoot(): string
    {
        return \dirname(__DIR__);
    }
}
