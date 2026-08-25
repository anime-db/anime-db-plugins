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
 * Builds the market registry's root plugins-registry.json from the current plugin manifests
 * of this monorepo and a caller-supplied list of already-published versions. Purely local: it
 * never talks to the network or to GitHub Releases itself, so collecting the published-versions
 * input (via `gh release ...`) and committing the resulting registry are left to CI tooling.
 *
 * Usage: php tools/build-registry.php <plugins-dir> <published-versions.json> [<previous-registry.json>] [<active-mirrors-file>] [<mirror-creds-env-var-name>]
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
 * <active-mirrors-file> and <mirror-creds-env-var-name>, when BOTH given, resolve `asset_mirrors`
 * as [GitHub] + every `MIRROR_CREDS` entry whose id is listed in <active-mirrors-file> (see
 * AssetMirrorsResolver, issue #26). A mirror id with no matching MIRROR_CREDS entry, or whose
 * public_url is not a well-formed "https://...<id>...<version>...<file>..." template, is dropped
 * with a "::warning::" line on STDERR — this NEVER fails the build (MIRROR_CREDS is a secret with
 * no PR gate; a typo in one replica must not block signing/publishing the registry for every
 * plugin). Warnings go to STDERR, not STDOUT, because STDOUT carries the registry JSON payload
 * (the caller redirects it to a file) — a "::warning::" line on STDOUT would corrupt that JSON and
 * so re-introduce the very build-wide freeze this fail-open design exists to prevent. When either
 * argument is omitted, `asset_mirrors` falls back to GitHub only, same as before this option existed.
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

use AnimeDb\Plugins\Tools\ActiveMirrorsFile;
use AnimeDb\Plugins\Tools\AssetMirrorsResolver;
use AnimeDb\Plugins\Tools\MirrorCredentialsParser;
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

    // Optional: absent entirely for every version published before this field existed
    // (release assets are immutable), present as an integer when the release asset's
    // manifest.json carried it.
    if (\array_key_exists('translation_keys_count', $entry) && !\is_int($entry['translation_keys_count'])) {
        fwrite(\STDERR, \sprintf(
            'Entry #%d in "%s" has a "translation_keys_count" field that is not an integer.'."\n",
            $index,
            $publishedVersionsPath,
        ));
        exit(1);
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

$activeMirrorsPath = $_SERVER['argv'][4] ?? null;
$credsEnvVar = $_SERVER['argv'][5] ?? null;

$assetMirrors = null;
if ($activeMirrorsPath !== null && $activeMirrorsPath !== '' && $credsEnvVar !== null && $credsEnvVar !== '') {
    if (!is_file($activeMirrorsPath)) {
        fwrite(\STDERR, \sprintf('Active mirrors file "%s" does not exist.'."\n", $activeMirrorsPath));
        exit(1);
    }

    $activeMirrorsContent = file_get_contents($activeMirrorsPath);
    if ($activeMirrorsContent === false) {
        fwrite(\STDERR, \sprintf('Failed to read "%s".'."\n", $activeMirrorsPath));
        exit(1);
    }

    $credsJson = getenv($credsEnvVar);
    $mirrorCredentials = [];
    if ($credsJson !== false && trim($credsJson) !== '') {
        try {
            $mirrorCredentials = (new MirrorCredentialsParser())->parse($credsJson);
        } catch (RuntimeException $exception) {
            fwrite(\STDERR, $exception->getMessage()."\n");
            exit(1);
        }
    }

    try {
        $activeMirrorIds = (new ActiveMirrorsFile())->parse($activeMirrorsContent);
    } catch (RuntimeException $exception) {
        fwrite(\STDERR, $exception->getMessage()."\n");
        exit(1);
    }

    $resolved = (new AssetMirrorsResolver())->resolve($mirrorCredentials, $activeMirrorIds);
    foreach ($resolved['warnings'] as $warning) {
        fwrite(\STDERR, "::warning::{$warning}\n");
    }
    $assetMirrors = $resolved['mirrors'];
}

/* @var list<array{id: string, version: string, core: string, sha256: string, translation_keys_count?: int}> $publishedVersions */
try {
    $registry = (new PluginRegistryBuilder())->build($pluginsDir, $publishedVersions, $sequence, $assetMirrors);
} catch (RuntimeException $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");
    exit(1);
}

fwrite(\STDOUT, json_encode(
    $registry,
    \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
)."\n");

exit(0);
