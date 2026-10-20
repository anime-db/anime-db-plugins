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
 * Guards tools/compile-plugin-container.php: the host registers every class of a plugin's
 * `src/` as an autowired service, and the gate must fail where that registration would.
 *
 * Fixtures are written at runtime into a temporary plugin directory (id `animedb-fx`, so the
 * namespace is `AnimeDb\Plugins\AnimedbFx\`).
 */
final class CompilePluginContainerCliTest extends TestCase
{
    private const NS = 'AnimeDb\Plugins\AnimedbFx';

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

    public function testPublicConstructorsCompile(): void
    {
        $plugin = $this->plugin([
            'Helper.php' => 'final class Helper { public function __construct() {} }',
            'Filler.php' => 'final class Filler implements \AnimeDb\PluginContracts\Filler\FillerInterface { use FillerStub; '
                .'public function __construct(Helper $helper, \Psr\Http\Client\ClientInterface $http) {} }',
        ], withFiller: true);

        [$exitCode, $output] = $this->compile($plugin);

        self::assertSame(0, $exitCode, $output);
    }

    public function testPrivateConstructorInjectedIntoFillerFails(): void
    {
        $plugin = $this->plugin([
            'Table.php' => 'final class Table { private function __construct() {} }',
            'Bridge.php' => 'final class Bridge implements \AnimeDb\PluginContracts\Filler\FillerInterface { use FillerStub; public function __construct(Table $table) {} }',
        ]);

        [$exitCode, $output] = $this->compile($plugin);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('constructor must be public', $output);
    }

    public function testPrivateConstructorInjectedIntoControllerFails(): void
    {
        $plugin = $this->plugin([
            'Table.php' => 'final class Table { private function __construct() {} }',
            'Ctl.php' => '#[\Symfony\Component\HttpKernel\Attribute\AsController] '
                .'final class Ctl { public function __construct(Table $table) {} }',
        ], withFiller: false);

        [$exitCode, $output] = $this->compile($plugin);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('constructor must be public', $output);
    }

    public function testPrivateConstructorOfANonInjectedUtilityPasses(): void
    {
        $plugin = $this->plugin([
            'Util.php' => 'final class Util { private function __construct() {} '
                .'public static function run(): int { return 1; } }',
            'Bridge.php' => 'final class Bridge implements \AnimeDb\PluginContracts\Filler\FillerInterface { use FillerStub; public function __construct() {} }',
        ]);

        [$exitCode, $output] = $this->compile($plugin);

        self::assertSame(0, $exitCode, $output);
    }

    public function testExternalTypeOutsideTheHostSurfaceFails(): void
    {
        $plugin = $this->plugin([
            'Bridge.php' => 'final class Bridge implements \AnimeDb\PluginContracts\Filler\FillerInterface { use FillerStub; '
                .'public function __construct(\DateTimeImmutable $now) {} }',
        ]);

        [$exitCode, $output] = $this->compile($plugin);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('DateTimeImmutable', $output);
    }

    public function testContractDtoAndRoleInterfaceAreNotHostServices(): void
    {
        foreach (['PluginAnimeData' => 'Filler\PluginAnimeData', 'FillerInterface' => 'Filler\FillerInterface'] as $name => $type) {
            $plugin = $this->plugin([
                'Bridge.php' => 'final class Bridge implements \AnimeDb\PluginContracts\Filler\FillerInterface { use FillerStub; '
                    .'public function __construct(\AnimeDb\PluginContracts\\'.$type.' $x) {} }',
            ]);

            [$exitCode, $output] = $this->compile($plugin);

            self::assertSame(1, $exitCode, $name.': '.$output);
        }
    }

    public function testHostAliasedContractInterfaceIsInjectable(): void
    {
        $plugin = $this->plugin([
            'Bridge.php' => 'final class Bridge implements \AnimeDb\PluginContracts\Filler\FillerInterface { use FillerStub; '
                .'public function __construct(\AnimeDb\PluginContracts\Download\DownloadServiceInterface $d, \Psr\Log\LoggerInterface $l) {} }',
        ]);

        [$exitCode, $output] = $this->compile($plugin);

        self::assertSame(0, $exitCode, $output);
    }

    public function testSymlinkedFileUnderSrcIsRefused(): void
    {
        $plugin = $this->plugin([
            'Bridge.php' => 'final class Bridge implements \AnimeDb\PluginContracts\Filler\FillerInterface { use FillerStub; }',
        ]);
        symlink('/etc/hostname', $plugin.'/src/Smuggled.php');

        [$exitCode, $output] = $this->compile($plugin);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('contains a symlink', $output);
    }

    public function testSrcThatIsASymlinkIsRefused(): void
    {
        $real = $this->temporaryDirectory().'/real-src';
        mkdir($real);
        $plugin = $this->temporaryDirectory().'/animedb-fx';
        mkdir($plugin);
        symlink($real, $plugin.'/src');

        [$exitCode, $output] = $this->compile($plugin);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('contains a symlink', $output);
    }

    public function testPluginWithoutSrcPasses(): void
    {
        $plugin = $this->temporaryDirectory().'/animedb-fx';
        mkdir($plugin, recursive: true);

        [$exitCode, $output] = $this->compile($plugin);

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * @dataProvider publishedPlugins
     */
    public function testEveryPublishedPluginCompiles(string $pluginDir): void
    {
        [$exitCode, $output] = $this->compile($pluginDir);

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publishedPlugins(): iterable
    {
        $directories = glob(\dirname(__DIR__).'/plugins/*', \GLOB_ONLYDIR);
        self::assertNotFalse($directories);

        foreach ($directories as $directory) {
            yield basename($directory) => [$directory];
        }
    }

    /**
     * @param array<string, string> $classes    file name => class declaration
     * @param bool                  $withFiller adds a `FillerStub` trait implementing the methods of `FillerInterface`
     */
    private function plugin(array $classes, bool $withFiller = true): string
    {
        $plugin = $this->temporaryDirectory().'/animedb-fx';
        mkdir($plugin.'/src', recursive: true);

        if ($withFiller) {
            $classes['FillerStub.php'] = 'trait FillerStub { '
                .'public function find(string $name, ?callable $onHeartbeat = null): array { return []; } '
                .'public function resolveExternalId(array $urls): ?string { return null; } '
                .'public function findById(string $externalId): ?\AnimeDb\PluginContracts\Filler\PluginAnimeData { return null; } '
                .'public function getFillableFields(): array { return []; } }';
        }

        foreach ($classes as $file => $declaration) {
            file_put_contents(
                $plugin.'/src/'.$file,
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".self::NS.";\n\n".$declaration."\n",
            );
        }

        return $plugin;
    }

    public function testPrValidationWorkflowCompilesTheAffectedPluginAfterThePluginBoundaryGate(): void
    {
        $workflow = Yaml::parseFile(\dirname(__DIR__).'/.github/workflows/pr-validation.yml');
        self::assertIsArray($workflow);

        $steps = $workflow['jobs']['validate']['steps'] ?? null;
        self::assertIsArray($steps, 'Job "validate" of pr-validation.yml has no steps.');

        $boundaryGateIndex = null;
        $compileIndexes = [];

        foreach ($steps as $index => $step) {
            $run = \is_array($step) && \is_string($step['run'] ?? null) ? $step['run'] : '';

            if (str_contains($run, 'tools/check-pr-changes.php')) {
                $boundaryGateIndex = $index;
            }

            if (!str_contains($run, 'tools/compile-plugin-container.php')) {
                continue;
            }

            $compileIndexes[] = $index;

            $condition = $step['if'] ?? null;
            self::assertIsString($condition, 'The container gate step must be conditional.');

            // On an infrastructural PR there is no affected plugin, and affected-plugin.txt
            // does not exist at all.
            self::assertStringContainsString(
                "steps.diff.outputs.touches_plugins == 'true'",
                $condition,
                'The container gate must be skipped on purely infrastructural PRs.',
            );

            // The step loads the PR's plugin classes. Any status function other than success()
            // — always(), cancelled(), failure() — drops the implicit success() and lets it run
            // after the boundary gate that vets the PR had failed.
            self::assertDoesNotMatchRegularExpression(
                '/\\b(always|cancelled|failure)\\s*\\(/',
                $condition,
                'The container gate must keep the implicit success(): it must not run once an earlier gate has failed.',
            );

            self::assertStringContainsString(
                'affected-plugin.txt',
                $run,
                'The container gate must compile the plugin the PR touches, not a fixed one.',
            );
        }

        self::assertIsInt($boundaryGateIndex, 'pr-validation.yml no longer runs check-pr-changes.php.');
        // Exactly one step: a duplicate placed before the boundary gate would otherwise hide
        // behind the compliant one.
        self::assertCount(1, $compileIndexes, 'pr-validation.yml must run compile-plugin-container.php in exactly one step.');
        self::assertGreaterThan(
            $boundaryGateIndex,
            $compileIndexes[0],
            'The container gate loads plugin code from the PR, so it must run after the boundary gate.',
        );
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/animedb-compile-'.bin2hex(random_bytes(6));
        mkdir($path, recursive: true);
        $this->temporaryDirectories[] = $path;

        return $path;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function compile(string $pluginDir): array
    {
        $output = [];
        exec(
            \sprintf(
                '%s %s %s 2>&1',
                escapeshellarg(\PHP_BINARY),
                escapeshellarg(\dirname(__DIR__).'/tools/compile-plugin-container.php'),
                escapeshellarg($pluginDir),
            ),
            $output,
            $exitCode,
        );

        return [$exitCode, implode("\n", $output)];
    }
}
