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
 * Runs PHPStan over the code of a single plugin with whatever rule set
 * anime-db/plugin-contracts currently declares in its `extension.neon` — that file is the
 * source of truth, not a list kept here. As of contract v0.17.1 it is three rules
 * (NoDangerousPrimitivesRule, ContractConformanceRule, NoNetworkAccessInLocalPluginsRule);
 * a contract release adding a fourth arms it here with no change to this script.
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
 *
 * Selecting those files by extension would leave the same hole one notch down, because
 * `require` executes whatever it is handed and never looks at the name: exec() in a published
 * `templates/pwn.phtml` (or `.inc`, or `.txt`) would ship, run, and never be analysed —
 * PHPStan skips a file whose extension is outside `fileExtensions` even when the path is
 * passed explicitly. Widening that list cannot close it either: the set of names PHP will
 * execute has no upper bound. So the rule here is about content, not names — a published file
 * that carries a PHP open tag while not being a `.php` file is REFUSED outright, the same way
 * an inline ignore annotation is. A file with no open tag is echoed verbatim by `require` and
 * can call nothing, so it is left alone.
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
 * Every file of the plugin that ends up in the distributable ZIP, plugin-relative and sorted,
 * so the set is a function of the plugin's contents and nothing else.
 *
 * Symlinks are dropped from the recursion itself rather than from the result — same mechanism
 * and same reason as PluginValidator's own scan: plugin
 * content is untrusted, and a symlink (at `/`, at `..`, or at a single file outside the
 * plugin) would otherwise be read and handed to PHPStan, which quotes source lines in its
 * reports. Two scripts walking the same untrusted tree must not disagree about symlinks.
 *
 * @return list<string>
 */
function collectPublishedFiles(string $pluginDir): array
{
    $files = [];

    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $fileInfo): bool => !$fileInfo->isLink(),
    );

    foreach (new RecursiveIteratorIterator($filter) as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
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

/**
 * Whether `require`ing this file would execute PHP from it.
 *
 * Decided by the open tag, not by the name: PHP runs whatever `require` is handed, and the tag
 * is case-insensitive (`<?PHP` runs). `<?=` counts too — it is always available, unlike the
 * `<?` short tag, which the host does not enable.
 */
function carriesPhpCode(string $path): bool
{
    $contents = file_get_contents($path);

    return $contents !== false && (stripos($contents, '<?php') !== false || str_contains($contents, '<?='));
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

$published = collectPublishedFiles($resolvedPluginDir);

// PHP smuggled into a published file that is not a `.php` file. Refused rather than analysed:
// PHPStan skips a path whose extension is outside `fileExtensions` even when it is passed
// explicitly, and no list of extensions can be complete, since `require` executes any name.
// Refusing keeps the rule exact — published content either carries no PHP at all, or is a
// `.php` file the gate analyses.
$smuggled = [];
$files = [];
foreach ($published as $file) {
    if (strtolower(pathinfo($file, \PATHINFO_EXTENSION)) === 'php') {
        $files[] = $file;

        continue;
    }

    if (carriesPhpCode($resolvedPluginDir.'/'.$file)) {
        $smuggled[] = $file;
    }
}

if ($smuggled !== []) {
    fwrite(\STDERR, \sprintf(
        "\"%s\" ships PHP code in files that are not PHP files, which the registry gate does not accept:\n",
        $pluginDir,
    ));
    foreach ($smuggled as $file) {
        fwrite(\STDERR, '  - '.$file." carries a PHP open tag\n");
    }
    exit(1);
}

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
