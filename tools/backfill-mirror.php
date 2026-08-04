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
 * Activates (or re-heals) one mirror from GitHub Releases (issue #26): re-projects every
 * historical plugin version onto <mirror-id>'s FTP(S) coordinates from MIRROR_CREDS,
 * HEAD-verifies each uploaded asset against its public_url, and — ONLY on full success — adds
 * <mirror-id> to <active-mirrors-file> (rewritten sorted/de-duplicated; left untouched on any
 * failure). Meant to run from a workflow_dispatch job; committing the rewritten
 * <active-mirrors-file> is left to that workflow (this script only edits the local file, same as
 * every other tool here never does its own git operations).
 *
 * Source of historical versions is GitHub Releases via `gh` (requires `gh` on PATH and
 * GH_TOKEN/GITHUB_TOKEN in the environment) — never another mirror over FTP (star topology: GitHub
 * is the hub). Read credentials for mirrors are therefore never needed.
 *
 * Usage: php tools/backfill-mirror.php <mirror-id> <mirror-creds-env-var-name> <active-mirrors-file>
 *
 * Unlike a release's HEAD-verify (soft-fail per mirror, see push-mirror-assets.php), HEAD here is
 * HARD-fail: this is not a release under time pressure, so it is safe (and correct) to refuse to
 * activate a mirror that cannot be shown to actually serve what was just uploaded to it.
 *
 * Exit code: 0 on success (mirror fully backfilled, active-mirrors file updated if needed),
 * 1 otherwise (active-mirrors file is left untouched).
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
use AnimeDb\Plugins\Tools\FtpMirrorTransport;
use AnimeDb\Plugins\Tools\GhReleaseAssetSource;
use AnimeDb\Plugins\Tools\HttpMirrorReachabilityChecker;
use AnimeDb\Plugins\Tools\MirrorAssetPublisher;
use AnimeDb\Plugins\Tools\MirrorAssetReachabilityVerifier;
use AnimeDb\Plugins\Tools\MirrorBackfillPublisher;
use AnimeDb\Plugins\Tools\MirrorCredentialsParser;

$mirrorId = $_SERVER['argv'][1] ?? null;
$credsEnvVar = $_SERVER['argv'][2] ?? null;
$activeMirrorsPath = $_SERVER['argv'][3] ?? null;

if (
    $mirrorId === null || $mirrorId === ''
    || $credsEnvVar === null || $credsEnvVar === ''
    || $activeMirrorsPath === null || $activeMirrorsPath === ''
) {
    fwrite(\STDERR, "Usage: php tools/backfill-mirror.php <mirror-id> <mirror-creds-env-var-name> <active-mirrors-file>\n");
    exit(1);
}

if (!is_file($activeMirrorsPath)) {
    fwrite(\STDERR, \sprintf('Active mirrors file "%s" does not exist.'."\n", $activeMirrorsPath));
    exit(1);
}

$credsJson = getenv($credsEnvVar);
if ($credsJson === false || trim($credsJson) === '') {
    fwrite(\STDERR, \sprintf('Environment variable "%s" is not set — cannot backfill without mirror credentials.'."\n", $credsEnvVar));
    exit(1);
}

try {
    $mirrors = (new MirrorCredentialsParser())->parse($credsJson);
} catch (RuntimeException $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");
    exit(1);
}

$mirror = $mirrors[$mirrorId] ?? null;
if ($mirror === null) {
    fwrite(\STDERR, \sprintf('MIRROR_CREDS has no entry for mirror "%s".'."\n", $mirrorId));
    exit(1);
}

$publisher = new MirrorBackfillPublisher(
    new GhReleaseAssetSource(),
    new MirrorAssetPublisher(new FtpMirrorTransport()),
    new MirrorAssetReachabilityVerifier(new HttpMirrorReachabilityChecker()),
);

try {
    $publisher->backfill($mirror);
} catch (RuntimeException $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");
    fwrite(\STDERR, \sprintf('Backfill of mirror "%s" failed — "%s" was NOT updated.'."\n", $mirrorId, $activeMirrorsPath));
    exit(1);
}

$activeMirrorsFile = new ActiveMirrorsFile();
$activeMirrorsContent = file_get_contents($activeMirrorsPath);
if ($activeMirrorsContent === false) {
    fwrite(\STDERR, \sprintf('Failed to read "%s".'."\n", $activeMirrorsPath));
    exit(1);
}

try {
    $activeMirrorIds = $activeMirrorsFile->parse($activeMirrorsContent);
} catch (RuntimeException $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");
    exit(1);
}

if (\in_array($mirrorId, $activeMirrorIds, true)) {
    fwrite(\STDOUT, \sprintf('Mirror "%s" backfilled successfully; already listed in "%s".'."\n", $mirrorId, $activeMirrorsPath));
    exit(0);
}

$activeMirrorIds[] = $mirrorId;
if (file_put_contents($activeMirrorsPath, $activeMirrorsFile->serialize($activeMirrorIds)) === false) {
    fwrite(\STDERR, \sprintf('Failed to write "%s".'."\n", $activeMirrorsPath));
    exit(1);
}

fwrite(\STDOUT, \sprintf('Mirror "%s" backfilled successfully and added to "%s".'."\n", $mirrorId, $activeMirrorsPath));

exit(0);
