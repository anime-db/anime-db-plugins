#!/usr/bin/env php
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

/*
 * Builds a deterministic distributable ZIP of a single plugin directory: manifest.json at the
 * archive root plus src/, README.md, etc., excluding vendor/, composer.lock, tests/, .git* and
 * .php-cs-fixer.*. Prints the archive's sha256 to stdout and writes it next to the archive as
 * a "sha256" file (the same asset name published alongside plugin.zip on a GitHub Release).
 *
 * Usage: php tools/build-plugin-zip.php <plugin-dir> <out.zip>
 * Exit code: 0 on success, 1 otherwise. Problems are printed to stderr.
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

use AnimeDb\Plugins\Tools\PluginZipBuilder;

$pluginDir = $_SERVER['argv'][1] ?? null;
$outZip = $_SERVER['argv'][2] ?? null;

if ($pluginDir === null || $pluginDir === '' || $outZip === null || $outZip === '') {
    fwrite(\STDERR, "Usage: php tools/build-plugin-zip.php <plugin-dir> <out.zip>\n");
    exit(1);
}

try {
    $sha256 = (new PluginZipBuilder())->build($pluginDir, $outZip);
} catch (RuntimeException $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");
    exit(1);
}

$sha256Path = \dirname($outZip).'/sha256';
if (file_put_contents($sha256Path, $sha256."\n") === false) {
    fwrite(\STDERR, \sprintf('Failed to write "%s".'."\n", $sha256Path));
    exit(1);
}

fwrite(\STDOUT, \sprintf("sha256: %s\n", $sha256));
exit(0);
