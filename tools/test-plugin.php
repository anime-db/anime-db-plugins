#!/usr/bin/env php
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

/*
 * Runs a single plugin's own PHPUnit suite (issue #147).
 *
 * Usage: php tools/test-plugin.php plugins/<id>
 * Exit code: 0 when the suite passes or the plugin ships no tests at all, 1 otherwise.
 *
 * Why this exists: a plugin's tests were executed by nothing. The repository's own
 * `composer test` runs the root phpunit.xml.dist, whose only testsuite is `tests` — the
 * tooling suite. `plugins/<id>/tests/` sat outside it, so a plugin could arrive with a full
 * suite, have it reviewed as evidence of correctness, and never have a single assertion
 * evaluated by CI. `analyse-plugin.php` is the only gate that reaches plugin code at all, and
 * it is static analysis: it sees types, never behaviour.
 *
 * The execution environment is this repository's own vendor/, and only it — the same rule
 * `analyse-plugin.php` states for analysis, for the same reason and with one more on top.
 * The shared reason: a plugin's vendor/ is never archived into the distributable ZIP (see
 * PluginZipBuilder), the host supplies every class it uses, so a plugin can only rely on what
 * the host has; the root require-dev is that surface, declared once and therefore checkable.
 * The extra reason specific to this script: installing a plugin's own composer.json would
 * execute it — Composer scripts and plugins run as the manifest that arrived in the pull
 * request asks — and would resolve its own anime-db/plugin-contracts version, so the suite
 * would be judged against a contract the host will not ship.
 *
 * The plugin's own phpunit.xml.dist is deliberately NOT used. It names
 * `bootstrap="vendor/autoload.php"`, i.e. the plugin's own vendor, which is exactly the
 * environment the paragraph above rules out — and it arrives in the same pull request as the
 * code under test, so honouring it would let a PR choose how it is tested. This script
 * supplies `--no-configuration`, its own bootstrap and the test directory explicitly, so the
 * environment is a property of this repository, not of the change being validated.
 *
 * Autoloading is rebuilt here from the plugin's composer.json `autoload` / `autoload-dev`
 * PSR-4 maps, read as data. Reading them is not the thing that was refused above: no Composer
 * process starts, no dependency is resolved, nothing from the manifest is executed. The maps
 * only say which namespace lives in which of the plugin's own directories, and getting that
 * wrong fails loudly (class not found) instead of silently widening what the suite may reach.
 *
 * Symlinks under tests/ are refused rather than followed. `analyse-plugin.php` and
 * PluginValidator both drop them while walking untrusted plugin content, and here the stake
 * is higher than there: this script does not read those files, it executes them, so a symlink
 * pointing outside the plugin would run code the gate never looked at.
 */

function locateAutoloader(): string
{
    foreach (['/../vendor/autoload.php', '/../../../autoload.php'] as $candidate) {
        $path = __DIR__.$candidate;
        if (is_file($path)) {
            return $path;
        }
    }

    fwrite(\STDERR, "Could not find vendor/autoload.php. Run `composer install` first.\n");
    exit(1);
}

$autoloader = locateAutoloader();
require $autoloader;

/**
 * The plugin's PSR-4 prefixes, merged from `autoload` and `autoload-dev`.
 *
 * Both halves are needed and neither is optional: `autoload` carries the plugin's own
 * namespace (the code under test), `autoload-dev` carries its tests. A plugin declaring
 * neither is not an error here — the caller has already established that it ships tests, and
 * a suite with no namespace to load will fail on its own terms, loudly.
 *
 * Only string targets and lists of strings are accepted, because those are the two shapes
 * Composer defines for a PSR-4 target. Anything else is a malformed manifest, and this script
 * is not the place that reports malformed manifests — it simply does not build an autoload
 * rule out of something it cannot read.
 *
 * @return array<string, list<string>> namespace prefix => absolute directories
 */
function collectPsr4Prefixes(string $pluginDir): array
{
    $composerFile = $pluginDir.'/composer.json';
    if (!is_file($composerFile)) {
        return [];
    }

    $contents = file_get_contents($composerFile);
    if ($contents === false) {
        return [];
    }

    try {
        $manifest = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }

    if (!\is_array($manifest)) {
        return [];
    }

    $prefixes = [];

    foreach (['autoload', 'autoload-dev'] as $section) {
        $psr4 = $manifest[$section]['psr-4'] ?? null;
        if (!\is_array($psr4)) {
            continue;
        }

        foreach ($psr4 as $prefix => $target) {
            if (!\is_string($prefix)) {
                continue;
            }

            foreach ((array) $target as $directory) {
                if (!\is_string($directory)) {
                    continue;
                }

                $prefixes[$prefix][] = rtrim($pluginDir.'/'.$directory, '/');
            }
        }
    }

    return $prefixes;
}

/**
 * Whether the directory tree contains a symlink at any depth.
 *
 * Checked before anything under it is executed. The walk must not follow links either, or a
 * link to `/` would make this scan unbounded rather than merely wrong — so the detection has
 * to happen in the recursion filter itself and be reported out of it by side effect.
 *
 * Inspecting the yielded entries instead does not work, and silently: a filter that answers
 * `!isLink()` removes every link from the iteration, so the loop that was meant to find them
 * is handed exactly the entries that are not links. The scan then reports "clean" for any
 * symlink below the top level.
 */
function containsSymlink(string $directory): bool
{
    if (is_link($directory)) {
        return true;
    }

    $found = false;

    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $fileInfo) use (&$found): bool {
            if ($fileInfo->isLink()) {
                $found = true;

                // Excluded from the walk as well as recorded: a linked directory must not be
                // descended into even though the answer is already decided, because the
                // iterator keeps walking after the callback returns.
                return false;
            }

            return true;
        },
    );

    // The loop body is empty on purpose: the callback above is what inspects the tree, and it
    // runs for every child the iterator considers — including directories, which are never
    // yielded in the default LEAVES_ONLY mode. A directory holding nothing but a symlink
    // therefore yields no entry at all, while still having set $found.
    foreach (new RecursiveIteratorIterator($filter) as $ignored) {
    }

    return $found;
}

/** @return list<string> */
function collectTestFiles(string $testsDir): array
{
    $files = [];

    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $fileInfo): bool => !$fileInfo->isLink(),
    );

    foreach (new RecursiveIteratorIterator($filter) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

$repoRoot = \dirname(__DIR__);

$pluginDir = $_SERVER['argv'][1] ?? null;
if ($pluginDir === null || $pluginDir === '') {
    fwrite(\STDERR, "Usage: php tools/test-plugin.php <plugin-dir>\n");
    exit(1);
}

$resolvedPluginDir = realpath($pluginDir);
if ($resolvedPluginDir === false || !is_dir($resolvedPluginDir)) {
    fwrite(\STDERR, \sprintf("Plugin directory \"%s\" does not exist.\n", $pluginDir));
    exit(1);
}

$phpunit = $repoRoot.'/vendor/bin/phpunit';
if (!is_file($phpunit)) {
    fwrite(\STDERR, "Could not find vendor/bin/phpunit. Run `composer install` first.\n");
    exit(1);
}

$testsDir = $resolvedPluginDir.'/tests';

// A plugin of type "translation" is a declarative resource with no PHP at all, and a plugin
// may legitimately arrive before its suite does. Reported explicitly rather than handed to
// PHPUnit as an empty run: PHPUnit exits non-zero on an empty suite, and "green because there
// was nothing to run" must be distinguishable from "green because it ran" in the log.
if (!is_dir($testsDir)) {
    fwrite(\STDOUT, \sprintf("SKIP: \"%s\" ships no tests/ directory — nothing to run.\n", $pluginDir));
    exit(0);
}

if (containsSymlink($testsDir)) {
    fwrite(\STDERR, \sprintf(
        '"%s" contains a symlink under tests/, which the registry gate does not accept: this '
        ."script executes that tree, so a link may not decide what runs.\n",
        $pluginDir,
    ));
    exit(1);
}

if (collectTestFiles($testsDir) === []) {
    fwrite(\STDOUT, \sprintf("SKIP: \"%s\" ships no *Test.php files — nothing to run.\n", $pluginDir));
    exit(0);
}

// tempnam(), not a path assembled by hand: it creates the file atomically with a name nothing
// can predict and mode 0600. The bootstrap is generated from this repository's own data, but a
// PHPUnit process then `require`s it, so a guessable path in a world-writable directory would
// be the one window where another process could swap its contents between write and read.
$bootstrap = tempnam(sys_get_temp_dir(), 'animedb-plugin-bootstrap-');
if ($bootstrap === false) {
    fwrite(\STDERR, "Could not create a temporary bootstrap file.\n");
    exit(1);
}

$generated = \sprintf(
    "<?php\n\ndeclare(strict_types=1);\n\nrequire %s;\n\n\$prefixes = %s;\n\n"
    ."spl_autoload_register(static function (string \$class) use (\$prefixes): void {\n"
    ."    foreach (\$prefixes as \$prefix => \$directories) {\n"
    ."        if (!str_starts_with(\$class, \$prefix)) {\n"
    ."            continue;\n"
    ."        }\n\n"
    ."        \$relative = str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))).'.php';\n\n"
    ."        foreach (\$directories as \$directory) {\n"
    ."            \$file = \$directory.'/'.\$relative;\n"
    ."            if (is_file(\$file)) {\n"
    ."                require \$file;\n\n"
    ."                return;\n"
    ."            }\n"
    ."        }\n"
    ."    }\n"
    ."});\n",
    var_export($autoloader, true),
    var_export(collectPsr4Prefixes($resolvedPluginDir), true),
);

if (file_put_contents($bootstrap, $generated) === false) {
    fwrite(\STDERR, "Could not write the temporary bootstrap file.\n");
    @unlink($bootstrap);
    exit(1);
}

// Same reason analyse-plugin.php chdir's here: the working directory is what a tool takes as
// its Composer project. --no-configuration is what keeps this honest either way — without it
// PHPUnit would discover the root phpunit.xml.dist and take its bootstrap and its testsuite,
// and from inside the plugin it would discover the plugin's, which is the environment this
// script exists to not use.
chdir($repoRoot);

$command = \sprintf(
    '%s %s --no-configuration --do-not-cache-result --colors=never --bootstrap %s %s',
    escapeshellarg(\PHP_BINARY),
    escapeshellarg($phpunit),
    escapeshellarg($bootstrap),
    escapeshellarg($testsDir),
);

$exitCode = 0;
passthru($command, $exitCode);

@unlink($bootstrap);

exit($exitCode === 0 ? 0 : 1);
