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
 * Guards the plugin code gate itself (issue #111).
 *
 * Both PHPStan rules of anime-db/plugin-contracts were written, released and never applied
 * to a single plugin: `phpstan.neon.dist` listed only `tools/`, and nothing anywhere asserted
 * that the rules actually fire. A green CI meant "the gate found nothing" and "the gate was
 * never pointed at anything" at the same time, and the two are indistinguishable from the
 * outside — which is why this test asserts the reported messages and not just the exit code:
 * a broken analysed path would also exit non-zero, and an exit-code-only test would call that
 * a working gate.
 *
 * The second half of the test does the same for the wiring: an analysis nobody runs is worth
 * as little as a rule nobody applies, so the workflow is read as data and checked for the step
 * that invokes it.
 */
final class AnalysePluginCliTest extends TestCase
{
    /**
     * Every report tests/fixtures/gate-probe must produce. Eight come from
     * NoDangerousPrimitivesRule, the last one from ContractConformanceRule — a rule set with
     * one of the two silently unregistered is exactly the failure this test exists to catch.
     *
     * @var list<string>
     */
    private const EXPECTED_REPORTS = [
        'Calling exec() directly is forbidden',
        'Calling shell_exec() directly is forbidden',
        'Calling curl_init() directly is forbidden',
        'Calling curl_exec() directly is forbidden',
        'Calling file_get_contents() with a URL is forbidden',
        'Calling stream_socket_client() directly is forbidden',
        'Using eval() is forbidden',
        'Using the shell exec operator (backticks) is forbidden',
        // Outside src/, in a directory PluginZipBuilder ships: an analysed set narrower than
        // the published set is a hole the width of a local `require`.
        'Calling passthru() directly is forbidden',
        'does not match the contract declared by',
    ];

    public function testGateReportsEveryViolationOfTheProbeFixture(): void
    {
        [$exitCode, $output] = $this->analyse(self::repoRoot().'/tests/fixtures/gate-probe');

        self::assertSame(1, $exitCode, $output);

        foreach (self::EXPECTED_REPORTS as $report) {
            self::assertStringContainsString($report, self::unwrap($output));
        }
    }

    public function testGateRejectsSuppressedAnalysisInPublishedCode(): void
    {
        // An inline @phpstan-ignore makes PHPStan itself report nothing at all, so this
        // rejection cannot come from a rule — only from the script refusing the file.
        [$exitCode, $output] = $this->analyse(self::repoRoot().'/tests/fixtures/gate-suppressed');

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('src/Suppressed.php contains @phpstan-ignore', $output);
    }

    public function testAnalysisConfigurationTakesTheContractFromThisRepository(): void
    {
        // A plugin's composer.json can declare its own repositories and pull a fork of
        // anime-db/plugin-contracts. Including that copy's extension.neon would let a pull
        // request supply the rules that judge it, so the include must stay inside this
        // repository's vendor/ — see .claude-docs/gotchas.md.
        $config = file_get_contents(self::repoRoot().'/tools/phpstan-plugin.neon.dist');
        self::assertIsString($config);

        self::assertMatchesRegularExpression('/^\s+- (\S+extension\.neon)$/m', $config);
        preg_match('/^\s+- (\S+extension\.neon)$/m', $config, $matches);

        $included = realpath(self::repoRoot().'/tools/'.$matches[1]);
        self::assertSame(
            realpath(self::repoRoot().'/vendor/anime-db/plugin-contracts/extension.neon'),
            $included,
        );
    }

    /**
     * Every published plugin passes the gate on `master`.
     *
     * The gate itself only ever runs against the plugin a pull request touches, so nothing
     * else notices when a plugin that nobody is editing drifts out of conformance — a new
     * contract minor flows in through the floating constraint and the first author to open an
     * unrelated pull request inherits the red. This test moves that discovery to the commit
     * that causes it.
     *
     * It is also what the framework packages in this repository's require-dev are for: without
     * them nothing outside CI could analyse a real plugin at all.
     *
     * @dataProvider publishedPlugins
     */
    public function testEveryPublishedPluginPassesTheGate(string $pluginDir): void
    {
        [$exitCode, $output] = $this->analyse($pluginDir);

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publishedPlugins(): iterable
    {
        $dirs = glob(self::repoRoot().'/plugins/*', \GLOB_ONLYDIR);
        self::assertIsArray($dirs);
        self::assertNotSame([], $dirs, 'No plugins found — the data provider would make this test vacuous.');

        foreach ($dirs as $dir) {
            yield basename($dir) => [$dir];
        }
    }

    public function testTranslationPluginIsSkippedRatherThanAnalysed(): void
    {
        // animedb-language-pack is of type "translation": PluginValidator forbids it a src/
        // directory, so the gate has nothing to analyse. It must say so instead of passing
        // through an empty file list — PHPStan 1.x answers that with exit 0 and 2.x with a
        // failure, and a green that flips on a dependency bump is what this gate exists to
        // remove.
        [$exitCode, $output] = $this->analyse(self::repoRoot().'/plugins/animedb-language-pack');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('ships no PHP files', $output);
    }

    public function testMissingPluginDirExitsNonZero(): void
    {
        [$exitCode, $output] = $this->analyse(self::repoRoot().'/plugins/does-not-exist');

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('does not exist', $output);
    }

    public function testPrValidationWorkflowRunsTheGateAfterThePluginBoundaryGate(): void
    {
        $workflow = Yaml::parseFile(self::repoRoot().'/.github/workflows/pr-validation.yml');
        self::assertIsArray($workflow);

        $steps = $workflow['jobs']['validate']['steps'] ?? null;
        self::assertIsArray($steps, 'Job "validate" of pr-validation.yml has no steps.');

        $boundaryGateIndex = null;
        $analysisIndex = null;

        foreach ($steps as $index => $step) {
            $run = \is_array($step) && \is_string($step['run'] ?? null) ? $step['run'] : '';

            if (str_contains($run, 'tools/check-pr-changes.php')) {
                $boundaryGateIndex = $index;
            }

            if (str_contains($run, 'tools/analyse-plugin.php')) {
                $analysisIndex = $index;

                // Same guarantee the "one plugin / only its code" gate gives every step after
                // it: on a plugin PR the configuration being executed is the base branch's.
                // Running the analysis before that gate would execute the plugin's own
                // composer.json from a mixed PR before anything checked it.
                self::assertSame(
                    "steps.diff.outputs.touches_plugins == 'true'",
                    $step['if'] ?? null,
                    'The plugin analysis step must be skipped on purely infrastructural PRs.',
                );

                // Without this the step would still "run the gate" and still be green
                // forever, pointed at a hardcoded plugin instead of the affected one.
                self::assertStringContainsString(
                    'affected-plugin.txt',
                    $run,
                    'The analysis must run against the plugin the PR touches, not a fixed one.',
                );
                self::assertStringNotContainsString(
                    '--working-dir',
                    $run,
                    'The analysis environment is this repository, never the plugin\'s own vendor/.',
                );
            }
        }

        self::assertNotNull($analysisIndex, 'pr-validation.yml never runs tools/analyse-plugin.php.');
        self::assertNotNull($boundaryGateIndex, 'pr-validation.yml never runs tools/check-pr-changes.php.');
        self::assertGreaterThan($boundaryGateIndex, $analysisIndex);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function analyse(string $pluginDir): array
    {
        exec(
            escapeshellarg(\PHP_BINARY).' '.escapeshellarg(self::repoRoot().'/tools/analyse-plugin.php').' '
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

    /**
     * PHPStan's default table output wraps long messages across lines, so a report is not a
     * contiguous substring of the raw output. Collapsing whitespace makes the assertions read
     * as the messages actually are instead of as the terminal happened to break them.
     */
    private static function unwrap(string $output): string
    {
        return (string) preg_replace('/\s+/', ' ', $output);
    }
}
