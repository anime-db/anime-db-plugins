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
 * One-time bootstrap tool for the maintainer: generates a fresh Ed25519 keypair for signing
 * plugins-registry.json. NOT part of CI — run locally, once, when setting up (or rotating) the
 * registry's signing key.
 *
 * Usage: php tools/generate-registry-keypair.php <public-key-out-file>
 * Writes the base64-encoded public key to <public-key-out-file> (safe to commit to the repo).
 * Prints the base64-encoded secret key to stdout: copy it into a GitHub Actions secret (e.g.
 * REGISTRY_SIGNING_KEY) and then discard it from your terminal/shell history — it must never be
 * committed to git.
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

$publicKeyOutPath = $_SERVER['argv'][1] ?? null;

if ($publicKeyOutPath === null || $publicKeyOutPath === '') {
    fwrite(\STDERR, "Usage: php tools/generate-registry-keypair.php <public-key-out-file>\n");
    exit(1);
}

if (is_file($publicKeyOutPath)) {
    fwrite(\STDERR, \sprintf('"%s" already exists — refusing to overwrite an existing key.'."\n", $publicKeyOutPath));
    exit(1);
}

$keyPair = (new PluginRegistrySigner())->generateKeyPair();

if (file_put_contents($publicKeyOutPath, $keyPair['public']."\n") === false) {
    fwrite(\STDERR, \sprintf('Failed to write "%s".'."\n", $publicKeyOutPath));
    exit(1);
}

fwrite(\STDERR, \sprintf('Public key written to "%s" — safe to commit.'."\n", $publicKeyOutPath));
fwrite(\STDERR, "Secret key (store as a GitHub Actions secret, then discard, never commit):\n");
fwrite(\STDOUT, $keyPair['secret']."\n");

exit(0);
