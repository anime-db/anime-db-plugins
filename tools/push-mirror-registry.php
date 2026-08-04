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
 * Pushes the signed registry (plugins-registry.json + plugins-registry.json.sig) to the root of
 * every mirror configured in the MIRROR_CREDS GitHub Actions secret, looping over its keys.
 * Unlike push-mirror-assets.php the registry is MUTABLE and is uploaded OVERWRITING the previous
 * copy on each mirror (see MirrorRegistryPublisher). Run after the registry is signed, from the
 * registry workflow.
 *
 * Usage: php tools/push-mirror-registry.php <registry-file> <signature-file> <mirror-creds-env-var-name>
 *
 * Exits 0 with no uploads when the given env var is unset/empty, or decodes to an empty JSON
 * object — mirrors are opt-in infra, not a required secret. Exits 1 on the first failure
 * (bad input, or a connect/auth/upload failure against ANY mirror).
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
use AnimeDb\Plugins\Tools\MirrorCredentialsParser;
use AnimeDb\Plugins\Tools\MirrorRegistryPublisher;

$registryPath = $_SERVER['argv'][1] ?? null;
$signaturePath = $_SERVER['argv'][2] ?? null;
$credsEnvVar = $_SERVER['argv'][3] ?? null;

if (
    $registryPath === null || $registryPath === ''
    || $signaturePath === null || $signaturePath === ''
    || $credsEnvVar === null || $credsEnvVar === ''
) {
    fwrite(\STDERR, "Usage: php tools/push-mirror-registry.php <registry-file> <signature-file> <mirror-creds-env-var-name>\n");
    exit(1);
}

foreach ([$registryPath, $signaturePath] as $path) {
    if (!is_file($path)) {
        fwrite(\STDERR, \sprintf('File "%s" does not exist.'."\n", $path));
        exit(1);
    }
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
    (new MirrorRegistryPublisher(new FtpMirrorTransport()))->publish($mirrors, $registryPath, $signaturePath);
} catch (RuntimeException $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");
    exit(1);
}

fwrite(\STDOUT, \sprintf('Pushed registry to %d mirror(s).'."\n", \count($mirrors)));

exit(0);
