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
 * Runs PHPStan with the anime-db/plugin-contracts rule set (NoDangerousPrimitivesRule,
 * ContractConformanceRule) over the code of a single plugin.
 *
 * Usage: php tools/analyse-plugin.php plugins/<id>
 * Exit code: 0 when the plugin is clean or ships no PHP at all, 1 otherwise.
 *
 * Why a separate entry point instead of adding plugins/ to phpstan.neon.dist: the two configs
 * answer different questions. The root one holds this repository's own tooling to its own
 * standard; this one holds a plugin to the contract, one plugin at a time, with the rules of
 * anime-db/plugin-contracts switched on.
 *
 * The analysis environment is this repository's own vendor/, and only it. That is not a
 * shortcut around the plugin's composer.json — it mirrors how a plugin actually runs: its
 * vendor/ is never archived into the distributable ZIP (see PluginZipBuilder), the host
 * supplies every class it uses, so a plugin can only rely on what the host has. Declaring the
 * host's surface once, in the repository's require-dev, is what makes that limit checkable;
 * installing whatever a plugin's own composer.json asks for would check it against a surface
 * that will not exist at runtime.
 *
 * The analysed set is every `.php` file the plugin actually ships, taken from
 * {@see PublishedContentRules} — the same list PluginZipBuilder archives and the version-bump
 * gate watches. Analysing `src/` alone would leave a hole the width of a `require`: a local
 * include is legitimate (only URL includes are forbidden), so a plugin could keep a clean
 * `src/` and put exec() in a `templates/*.php` that ships in the very same ZIP.
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

require locateAutoloader();

use AnimeDb\Plugins\Tools\PublishedContentRules;

/**
 * Every `.php` file of the plugin that ends up in the distributable ZIP, plugin-relative and
 * sorted, so the analysed set is a function of the plugin's contents and nothing else.
 *
 * @return list<string>
 */
function collectPublishedPhpFiles(string $pluginDir): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        if (strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), \strlen($pluginDir) + 1);

        if (PublishedContentRules::isExcluded($relative)) {
            continue;
        }

        $files[] = $relative;
    }

    sort($files);

    return $files;
}

$repoRoot = \dirname(__DIR__);

$pluginDir = $_SERVER['argv'][1] ?? null;
if ($pluginDir === null || $pluginDir === '') {
    fwrite(\STDERR, "Usage: php tools/analyse-plugin.php <plugin-dir>\n");
    exit(1);
}

$resolvedPluginDir = realpath($pluginDir);
if ($resolvedPluginDir === false || !is_dir($resolvedPluginDir)) {
    fwrite(\STDERR, \sprintf("Plugin directory \"%s\" does not exist.\n", $pluginDir));
    exit(1);
}

$phpstan = $repoRoot.'/vendor/bin/phpstan';
if (!is_file($phpstan)) {
    fwrite(\STDERR, "Could not find vendor/bin/phpstan. Run `composer install` first.\n");
    exit(1);
}

$config = $repoRoot.'/tools/phpstan-plugin.neon.dist';
if (!is_file($config)) {
    fwrite(\STDERR, \sprintf("Missing PHPStan configuration \"%s\".\n", $config));
    exit(1);
}

$files = collectPublishedPhpFiles($resolvedPluginDir);

// A plugin of type "translation" is a declarative resource and must not contain src/ at all,
// so it legitimately ships no PHP. Reported explicitly rather than handed to PHPStan with an
// empty file list: PHPStan 1.x answers that with a warning and exit code 0, PHPStan 2.x with
// a failure — a silent green that flips to red on a dependency bump is the exact shape of
// problem this gate exists to remove.
if ($files === []) {
    fwrite(\STDOUT, \sprintf("SKIP: \"%s\" ships no PHP files — nothing to analyse.\n", $pluginDir));
    exit(0);
}

// PHPStan's inline ignore annotation suppresses any rule on the next line, the two contract
// rules included, and leaves no trace in a green run. Silencing a linter with a comment is
// the ordinary reaction to a red build, not an obfuscated bypass, so a gate that accepts one
// gates nothing. The ban covers the analysed (= published) files only; a plugin's own tests
// are free to use it.
//
// The annotation is named in a string literal and nowhere in a comment on purpose: PHPStan
// reads this file too, and in a comment the name would be an ignore annotation rather than
// a mention of one (it parses as one, and reports the parse error).
$needle = '@phpstan-ignore';

$suppressed = [];
foreach ($files as $file) {
    $contents = file_get_contents($resolvedPluginDir.'/'.$file);
    if ($contents !== false && str_contains($contents, $needle)) {
        $suppressed[] = $file;
    }
}

if ($suppressed !== []) {
    fwrite(\STDERR, \sprintf(
        "\"%s\" suppresses static analysis in published code, which the registry gate does not accept:\n",
        $pluginDir,
    ));
    foreach ($suppressed as $file) {
        fwrite(\STDERR, '  - '.$file.' contains '.$needle."\n");
    }
    exit(1);
}

// The working directory is what tells PHPStan which Composer project the analysed code
// belongs to, and it must be this repository — not the plugin directory. Run from inside the
// plugin, PHPStan takes that plugin's own vendor/ as the project: its dependencies, and its
// own copy of anime-db/plugin-contracts, would be what the plugin is judged against, both of
// them installed from a composer.json that arrived in the very same pull request as the code
// being gated. --autoload-file has the same effect for the same reason (Composer registers
// its autoloader with prepend = true, so the plugin's loader wins over this one).
chdir($repoRoot);

$command = \sprintf(
    '%s %s analyse --configuration=%s --no-progress --no-interaction %s',
    escapeshellarg(\PHP_BINARY),
    escapeshellarg($phpstan),
    escapeshellarg($config),
    implode(' ', array_map(
        static fn (string $file): string => escapeshellarg($resolvedPluginDir.'/'.$file),
        $files,
    )),
);

$exitCode = 0;
passthru($command, $exitCode);

exit($exitCode === 0 ? 0 : 1);
