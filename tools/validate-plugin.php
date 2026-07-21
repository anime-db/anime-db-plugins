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
 * Validates a single plugin directory: manifest.json presence/content, id-vs-directory-name
 * match, src/ presence, namespace correctness, and PHP syntax.
 *
 * Usage: php tools/validate-plugin.php plugins/<id>
 * Exit code: 0 if the plugin is valid, 1 otherwise. Problems are printed to stderr, one per line.
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

use AnimeDb\Plugins\Tools\PluginValidator;

$pluginDir = $_SERVER['argv'][1] ?? null;
if (null === $pluginDir || '' === $pluginDir) {
    fwrite(\STDERR, "Usage: php tools/validate-plugin.php <plugin-dir>\n");
    exit(1);
}

$errors = (new PluginValidator())->validate($pluginDir);

if ([] === $errors) {
    fwrite(\STDOUT, \sprintf("OK: \"%s\" is a valid plugin.\n", $pluginDir));
    exit(0);
}

fwrite(\STDERR, \sprintf("\"%s\" is not a valid plugin:\n", $pluginDir));
foreach ($errors as $error) {
    fwrite(\STDERR, '  - '.$error."\n");
}
exit(1);
