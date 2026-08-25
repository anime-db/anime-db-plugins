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
 * Gate: whenever a PR changes a plugin's published content (the files
 * tools/build-plugin-zip.php actually archives — see PublishedContentRules), the plugin's
 * plugins/<id>/manifest.json version must be strictly greater than the base branch's, and
 * the resulting <id>/<version> release tag must not already exist.
 *
 * Usage:
 *   git diff --name-only <base-sha>...<head-sha> | php tools/check-version-bump.php <base-ref>
 *
 * The working tree must already be checked out at the PR head (true for the
 * pr-validation.yml gate); the base manifest is read via `git show <base-ref>:...` without
 * needing a second checkout. Tag existence is checked via `git ls-remote --exit-code`
 * against the `origin` remote, which needs no `gh` binary and no token — the repository is
 * public, so an anonymous fetch is enough.
 *
 * On success (no violations) exits 0 silently. On failure prints one line per violation to
 * stderr and exits 1 — including when the tag-existence check itself could not be
 * completed (network failure, etc.): that is reported as a violation too, not skipped.
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

use AnimeDb\Plugins\Tools\GitManifestVersionSource;
use AnimeDb\Plugins\Tools\GitTagExistenceChecker;
use AnimeDb\Plugins\Tools\VersionBumpChecker;

$baseRef = $_SERVER['argv'][1] ?? null;

if ($baseRef === null || $baseRef === '') {
    fwrite(\STDERR, "Usage: git diff --name-only <base-sha>...<head-sha> | php tools/check-version-bump.php <base-ref>\n");
    exit(1);
}

$stdin = stream_get_contents(\STDIN);
// No callback here: VersionBumpChecker::check() already trims and drops blank lines via its
// own path grouping, same reasoning as check-pr-changes.php.
$paths = $stdin === false ? [] : (preg_split('/\R/', $stdin) ?: []);

$repoRoot = \dirname(__DIR__);
$manifests = new GitManifestVersionSource($baseRef, $repoRoot);
$tags = new GitTagExistenceChecker();

$result = (new VersionBumpChecker())->check($paths, $manifests, $tags);

if ($result->isValid()) {
    exit(0);
}

fwrite(\STDERR, "Rejected: published plugin content changed without a valid version bump:\n");
foreach ($result->violations as $violation) {
    fwrite(\STDERR, '  - '.$violation->message."\n");
}
exit(1);
