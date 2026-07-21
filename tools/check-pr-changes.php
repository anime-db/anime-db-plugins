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
 * Gate: a PR must touch exactly one plugin, and only paths inside its plugins/<id>/
 * directory (plugins-directory.json is a CI-generated artifact and may not be hand-edited).
 *
 * Usage:
 *   php tools/check-pr-changes.php <path> [<path> ...]
 *   git diff --name-only master... | php tools/check-pr-changes.php
 *
 * On success prints the single affected plugin id to stdout and exits 0.
 * On failure prints the reason(s) to stderr, one per line, and exits 1.
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

use AnimeDb\Plugins\Tools\PrChangeChecker;

$paths = \array_slice($_SERVER['argv'], 1);

if ($paths === []) {
    $stdin = stream_get_contents(\STDIN);
    // No callback here: PrChangeChecker::check() already trims and drops
    // blank lines, so a literal "0" path line is not lost as falsy.
    $paths = $stdin === false ? [] : (preg_split('/\R/', $stdin) ?: []);
}

$result = (new PrChangeChecker())->check($paths);

if ($result->isValid()) {
    fwrite(\STDOUT, $result->pluginId."\n");
    exit(0);
}

fwrite(\STDERR, "Rejected: change set does not gate to a single plugin:\n");
foreach ($result->errors as $error) {
    fwrite(\STDERR, '  - '.$error."\n");
}
exit(1);
