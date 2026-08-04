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
 * Generates the markdown release notes body for a single plugin's release, GitHub-"Generate
 * release notes"-style but scoped to `plugins/<id>/` in this monorepo (see issue #28).
 * A pure, network- and git-free formatter: collecting the raw material (tags, `git log`, `gh api
 * .../commits/<sha>/pulls`) and wiring it into the release workflow with `--notes-file` is the
 * caller's job, not this script's — see the "Границы" section of issue #28.
 *
 * Two subcommands:
 *
 *   php tools/build-release-notes.php pick-prev <version>
 *     Reads the plugin's existing tags from stdin, one per line ("<id>/<X.Y.Z>"), and prints
 *     the greatest tag whose version is strictly less than <version> (semantic comparison).
 *     Prints an empty line (exit 0) when there is no earlier version (first release).
 *
 *   php tools/build-release-notes.php format --id <plugin-id> --version <X.Y.Z> \
 *       --repo <owner/name> [--prev <prev-tag>]
 *     Reads a JSON array of commits (already filtered to the plugin's path) from stdin and
 *     prints the release notes body to stdout.
 *
 * Exit code: 0 on success, 1 on any error (usage, malformed input) — the message goes to stderr
 * and nothing partial is printed to stdout, so a caller can safely treat "exit 1" as "notes not
 * generated" and fall back to a placeholder body.
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

use AnimeDb\Plugins\Tools\ReleaseCommitsJsonParser;
use AnimeDb\Plugins\Tools\ReleaseNotesFormatter;
use AnimeDb\Plugins\Tools\ReleaseNotesPreviousTagPicker;

const USAGE = <<<'USAGE'
    Usage:
      php tools/build-release-notes.php pick-prev <version>
      php tools/build-release-notes.php format --id <plugin-id> --version <X.Y.Z> --repo <owner/name> [--prev <prev-tag>]

    USAGE;

/**
 * Minimal `--name value` flag parser for the "format" subcommand's args (everything after the
 * subcommand itself) — no positional arguments are used, so this stays a flat map.
 *
 * @param list<string> $args
 *
 * @return array<string, string>
 */
function parseFlags(array $args): array
{
    $flags = [];
    $count = \count($args);
    for ($i = 0; $i < $count; ++$i) {
        $arg = $args[$i];
        if (!str_starts_with($arg, '--')) {
            throw new RuntimeException(\sprintf('Unexpected argument "%s".', $arg));
        }

        $name = substr($arg, 2);
        $value = $args[$i + 1] ?? null;
        if ($value === null) {
            throw new RuntimeException(\sprintf('Flag "--%s" requires a value.', $name));
        }

        $flags[$name] = $value;
        ++$i;
    }

    return $flags;
}

$subcommand = $_SERVER['argv'][1] ?? null;

if ($subcommand === 'pick-prev') {
    $version = $_SERVER['argv'][2] ?? null;
    if ($version === null || $version === '') {
        fwrite(\STDERR, USAGE);
        exit(1);
    }

    $stdin = stream_get_contents(\STDIN);
    $tags = $stdin === false ? [] : (preg_split('/\R/', $stdin) ?: []);

    try {
        $previous = (new ReleaseNotesPreviousTagPicker())->pickPrevious($version, $tags);
    } catch (RuntimeException $exception) {
        fwrite(\STDERR, $exception->getMessage()."\n");
        exit(1);
    }

    fwrite(\STDOUT, ($previous ?? '')."\n");
    exit(0);
}

if ($subcommand === 'format') {
    try {
        $flags = parseFlags(\array_slice($_SERVER['argv'], 2));
    } catch (RuntimeException $exception) {
        fwrite(\STDERR, $exception->getMessage()."\n".USAGE);
        exit(1);
    }

    $id = $flags['id'] ?? null;
    $version = $flags['version'] ?? null;
    $repo = $flags['repo'] ?? null;
    $prev = $flags['prev'] ?? null;

    if ($id === null || $id === '' || $version === null || $version === '' || $repo === null || $repo === '') {
        fwrite(\STDERR, USAGE);
        exit(1);
    }

    $stdin = stream_get_contents(\STDIN);

    try {
        $commits = (new ReleaseCommitsJsonParser())->parse($stdin === false ? '' : $stdin);
        $notes = (new ReleaseNotesFormatter())->format($id, $version, $repo, $prev, $commits);
    } catch (RuntimeException $exception) {
        fwrite(\STDERR, $exception->getMessage()."\n");
        exit(1);
    }

    fwrite(\STDOUT, $notes."\n");
    exit(0);
}

fwrite(\STDERR, USAGE);
exit(1);
