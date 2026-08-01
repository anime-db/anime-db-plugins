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
 * Builds the market registry's root plugins-registry.json from the current plugin manifests
 * of this monorepo and a caller-supplied list of already-published versions. Purely local: it
 * never talks to the network or to GitHub Releases itself, so collecting the published-versions
 * input (via `gh release ...`) and committing the resulting registry are left to CI tooling.
 *
 * Usage: php tools/build-registry.php <plugins-dir> <published-versions.json> [<previous-registry.json>]
 * Prints the generated JSON to stdout. Do NOT redirect stdout directly onto the previous
 * registry path (`> plugins-registry.json`): the shell truncates that file before this script
 * runs, so it would read back empty. Write to a new path and move it into place instead:
 *   php tools/build-registry.php plugins published-versions.json plugins-registry.json \
 *     > plugins-registry.json.new
 *   mv plugins-registry.json.new plugins-registry.json
 *
 * <previous-registry.json>, when given and existing, supplies the previous "sequence" value;
 * the new registry's sequence is that value + 1 (anti-rollback: strictly increasing between
 * generations). When omitted, or when the path does not exist yet (first ever generation),
 * sequence starts at 1.
 *
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

use AnimeDb\Plugins\Tools\PluginRegistryBuilder;

$pluginsDir = $_SERVER['argv'][1] ?? null;
$publishedVersionsPath = $_SERVER['argv'][2] ?? null;

if ($pluginsDir === null || $pluginsDir === '' || $publishedVersionsPath === null || $publishedVersionsPath === '') {
    fwrite(\STDERR, "Usage: php tools/build-registry.php <plugins-dir> <published-versions.json>\n");
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

$previousRegistryPath = $_SERVER['argv'][3] ?? null;
$sequence = 1;
if ($previousRegistryPath !== null && $previousRegistryPath !== '' && is_file($previousRegistryPath)) {
    $previousRegistryJson = file_get_contents($previousRegistryPath);
    if ($previousRegistryJson === false) {
        fwrite(\STDERR, \sprintf('Failed to read "%s".'."\n", $previousRegistryPath));
        exit(1);
    }

    try {
        $previousRegistry = json_decode($previousRegistryJson, true, 512, \JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fwrite(\STDERR, \sprintf('"%s" is not valid JSON: %s'."\n", $previousRegistryPath, $exception->getMessage()));
        exit(1);
    }

    if (!\is_array($previousRegistry) || !\is_int($previousRegistry['sequence'] ?? null) || $previousRegistry['sequence'] < 1) {
        fwrite(\STDERR, \sprintf('"%s" does not contain a valid positive-integer "sequence" field.'."\n", $previousRegistryPath));
        exit(1);
    }

    $sequence = $previousRegistry['sequence'] + 1;
}

/* @var list<array{id: string, version: string, core: string, sha256: string}> $publishedVersions */
try {
    $registry = (new PluginRegistryBuilder())->build($pluginsDir, $publishedVersions, $sequence);
} catch (RuntimeException $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");
    exit(1);
}

fwrite(\STDOUT, json_encode(
    $registry,
    \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
)."\n");

exit(0);
