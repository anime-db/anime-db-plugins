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
 * Pushes one plugin version's release assets (plugin.zip, manifest.json) to every mirror
 * configured in the MIRROR_CREDS GitHub Actions secret, looping over its keys — adding a mirror
 * means adding a key to that JSON, never a new secret. The on-mirror layout is the
 * version-immutable <id>/<version>/<file> tree; a file already present on a mirror is never
 * overwritten, so re-running this script for an already-published version is a safe no-op.
 *
 * Usage: php tools/push-mirror-assets.php <plugin-id> <version> <assets-dir> <mirror-creds-env-var-name>
 * <assets-dir> must contain plugin.zip and manifest.json.
 *
 * Exits 0 with no uploads when the given env var is unset/empty, or decodes to an empty JSON
 * object — mirrors are opt-in infra, not a required secret. Exits 1 on the first failure (bad
 * input, or a connect/auth/upload failure against ANY mirror): the caller (CI) MUST treat that
 * as "do not publish the registry" — the assets-before-registry invariant means a
 * partially-mirrored version must never be advertised as available (see issue #14).
 *
 * After a mirror's upload succeeds, its public_url is HEAD-verified for each asset (issue #26).
 * Unlike the upload itself, a failed HEAD check is SOFT: it prints a loud "::warning::" line and
 * the script still exits 0 — a release must not be blocked by one replica's transient propagation
 * lag or outage, since GitHub Releases (asset_mirrors[0]) already serves the asset either way.
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

use AnimeDb\Plugins\Tools\FtpMirrorTransport;
use AnimeDb\Plugins\Tools\HttpMirrorReachabilityChecker;
use AnimeDb\Plugins\Tools\MirrorAssetPublisher;
use AnimeDb\Plugins\Tools\MirrorAssetReachabilityVerifier;
use AnimeDb\Plugins\Tools\MirrorCredentialsParser;

$pluginId = $_SERVER['argv'][1] ?? null;
$version = $_SERVER['argv'][2] ?? null;
$assetsDir = $_SERVER['argv'][3] ?? null;
$credsEnvVar = $_SERVER['argv'][4] ?? null;

if (
    $pluginId === null || $pluginId === ''
    || $version === null || $version === ''
    || $assetsDir === null || $assetsDir === ''
    || $credsEnvVar === null || $credsEnvVar === ''
) {
    fwrite(\STDERR, "Usage: php tools/push-mirror-assets.php <plugin-id> <version> <assets-dir> <mirror-creds-env-var-name>\n");
    exit(1);
}

if (!is_dir($assetsDir)) {
    fwrite(\STDERR, \sprintf('Assets directory "%s" does not exist.'."\n", $assetsDir));
    exit(1);
}

$credsJson = getenv($credsEnvVar);
if ($credsJson === false || trim($credsJson) === '') {
    fwrite(\STDOUT, \sprintf('Environment variable "%s" is not set — no mirrors configured, nothing to push.'."\n", $credsEnvVar));
    exit(0);
}

try {
    $mirrors = (new MirrorCredentialsParser())->parse($credsJson);
} catch (RuntimeException $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");
    exit(1);
}

if ($mirrors === []) {
    fwrite(\STDOUT, "MIRROR_CREDS decodes to no mirrors — nothing to push.\n");
    exit(0);
}

try {
    (new MirrorAssetPublisher(new FtpMirrorTransport()))->publish(
        $mirrors,
        $pluginId,
        $version,
        $assetsDir,
        ['plugin.zip', 'manifest.json'],
    );
} catch (RuntimeException $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");
    exit(1);
}

fwrite(\STDOUT, \sprintf('Pushed %s/%s to %d mirror(s).'."\n", $pluginId, $version, \count($mirrors)));

$reports = (new MirrorAssetReachabilityVerifier(new HttpMirrorReachabilityChecker()))->verify(
    $mirrors,
    $pluginId,
    $version,
    ['plugin.zip', 'manifest.json'],
);
foreach ($reports as $report) {
    if (!$report->reachable) {
        fwrite(\STDOUT, \sprintf('::warning::Mirror "%s" uploaded successfully but "%s" is not reachable yet — leaving asset_mirrors unchanged, GitHub remains authoritative.'."\n", $report->mirrorId, $report->url));
    }
}

exit(0);
