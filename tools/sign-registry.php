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
 * Produces a detached Ed25519 signature over the exact bytes of a file (in practice, the
 * published plugins-registry.json), so CI can publish it alongside as plugins-registry.json.sig.
 *
 * The secret key is read from an environment variable (base64-encoded), never from argv or a
 * committed file, so it never appears in a process listing and stays out of git — CI supplies
 * it from a GitHub Actions secret (`env: <name>: ${{ secrets.<name> }}`).
 *
 * Usage: php tools/sign-registry.php <file-to-sign> <secret-key-env-var-name>
 * Prints the base64 signature to stdout. Exit code: 0 on success, 1 otherwise.
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

use AnimeDb\Plugins\Tools\PluginRegistrySigner;

$filePath = $_SERVER['argv'][1] ?? null;
$secretKeyEnvVar = $_SERVER['argv'][2] ?? null;

if ($filePath === null || $filePath === '' || $secretKeyEnvVar === null || $secretKeyEnvVar === '') {
    fwrite(\STDERR, "Usage: php tools/sign-registry.php <file-to-sign> <secret-key-env-var-name>\n");
    exit(1);
}

if (!is_file($filePath)) {
    fwrite(\STDERR, \sprintf('"%s" does not exist.'."\n", $filePath));
    exit(1);
}

$message = file_get_contents($filePath);
if ($message === false) {
    fwrite(\STDERR, \sprintf('Failed to read "%s".'."\n", $filePath));
    exit(1);
}

$secretKeyBase64 = getenv($secretKeyEnvVar);
if ($secretKeyBase64 === false || $secretKeyBase64 === '') {
    fwrite(\STDERR, \sprintf('Environment variable "%s" is not set.'."\n", $secretKeyEnvVar));
    exit(1);
}

try {
    $signature = (new PluginRegistrySigner())->sign($message, $secretKeyBase64);
} catch (RuntimeException $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");
    exit(1);
}

fwrite(\STDOUT, $signature."\n");

exit(0);
