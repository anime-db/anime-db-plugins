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
 * Builds the market registry's root plugins-directory.json from the current plugin manifests
 * of this monorepo and a caller-supplied list of already-published versions. Purely local: it
 * never talks to the network or to GitHub Releases itself, so collecting the published-versions
 * input (via `gh release ...`) and committing the resulting directory are left to CI tooling.
 *
 * Usage: php tools/build-directory.php <plugins-dir> <published-versions.json>
 * Prints the generated JSON to stdout; redirect it to plugins-directory.json.
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

use AnimeDb\Plugins\Tools\PluginDirectoryBuilder;

$pluginsDir = $_SERVER['argv'][1] ?? null;
$publishedVersionsPath = $_SERVER['argv'][2] ?? null;

if ($pluginsDir === null || $pluginsDir === '' || $publishedVersionsPath === null || $publishedVersionsPath === '') {
    fwrite(\STDERR, "Usage: php tools/build-directory.php <plugins-dir> <published-versions.json>\n");
    exit(1);
}

if (!is_file($publishedVersionsPath)) {
    fwrite(\STDERR, \sprintf('Published versions file "%s" does not exist.'."\n", $publishedVersionsPath));
    exit(1);
}

$publishedVersionsJson = file_get_contents($publishedVersionsPath);
if ($publishedVersionsJson === false) {
    fwrite(\STDERR, \sprintf('Failed to read "%s".'."\n", $publishedVersionsPath));
    exit(1);
}

try {
    $publishedVersions = json_decode($publishedVersionsJson, true, 512, \JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(\STDERR, \sprintf('"%s" is not valid JSON: %s'."\n", $publishedVersionsPath, $exception->getMessage()));
    exit(1);
}

if (!\is_array($publishedVersions) || !array_is_list($publishedVersions)) {
    fwrite(\STDERR, \sprintf('"%s" must contain a JSON array at the top level.'."\n", $publishedVersionsPath));
    exit(1);
}

foreach ($publishedVersions as $index => $entry) {
    if (!\is_array($entry)) {
        fwrite(\STDERR, \sprintf('Entry #%d in "%s" must be a JSON object.'."\n", $index, $publishedVersionsPath));
        exit(1);
    }

    foreach (['id', 'version', 'core', 'sha256'] as $field) {
        if (!\is_string($entry[$field] ?? null) || $entry[$field] === '') {
            fwrite(\STDERR, \sprintf(
                'Entry #%d in "%s" is missing a non-empty "%s" string field.'."\n",
                $index,
                $publishedVersionsPath,
                $field,
            ));
            exit(1);
        }
    }
}

/* @var list<array{id: string, version: string, core: string, sha256: string}> $publishedVersions */
try {
    $directory = (new PluginDirectoryBuilder())->build($pluginsDir, $publishedVersions);
} catch (RuntimeException $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");
    exit(1);
}

fwrite(\STDOUT, json_encode(
    $directory,
    \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
)."\n");

exit(0);
