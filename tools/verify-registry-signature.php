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
 * Verifies a detached Ed25519 signature (as produced by tools/sign-registry.php) over the exact
 * bytes of a file against a base64-encoded public key. Reference implementation for the check
 * the client (issue #292) will perform; also useful for CI to self-check what it just signed.
 *
 * Usage: php tools/verify-registry-signature.php <file> <signature-file> <public-key-file>
 * Exit code: 0 if the signature is valid, 1 otherwise. Result is printed to stdout/stderr.
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
$signaturePath = $_SERVER['argv'][2] ?? null;
$publicKeyPath = $_SERVER['argv'][3] ?? null;

if (
    $filePath === null || $filePath === ''
    || $signaturePath === null || $signaturePath === ''
    || $publicKeyPath === null || $publicKeyPath === ''
) {
    fwrite(\STDERR, "Usage: php tools/verify-registry-signature.php <file> <signature-file> <public-key-file>\n");
    exit(1);
}

foreach (['file' => $filePath, 'signature' => $signaturePath, 'public key' => $publicKeyPath] as $label => $path) {
    if (!is_file($path)) {
        fwrite(\STDERR, \sprintf('%s "%s" does not exist.'."\n", ucfirst($label), $path));
        exit(1);
    }
}

$message = file_get_contents($filePath);
$signatureBase64 = file_get_contents($signaturePath);
$publicKeyBase64 = file_get_contents($publicKeyPath);

if ($message === false || $signatureBase64 === false || $publicKeyBase64 === false) {
    fwrite(\STDERR, "Failed to read one of the input files.\n");
    exit(1);
}

$isValid = (new PluginRegistrySigner())->verify(
    $message,
    trim($signatureBase64),
    trim($publicKeyBase64),
);

if ($isValid) {
    fwrite(\STDOUT, "OK: signature is valid.\n");
    exit(0);
}

fwrite(\STDERR, "Signature verification FAILED.\n");
exit(1);
