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

namespace AnimeDb\Plugins\Tools;

/**
 * Gates a PR to bumping `version` in `plugins/<id>/manifest.json` whenever it changes that
 * plugin's published content — the content {@see PluginZipBuilder} actually archives, per
 * {@see PublishedContentRules}. Without this, `release.yml` finds the base branch's tag
 * `<id>/<version>` already published and silently skips the plugin: the change never reaches
 * users and CI stays green.
 *
 * Two conditions both must hold for a touched plugin:
 *
 *  1. the head version must compare strictly greater than the base version
 *     ({@see version_compare()} `>`) — catches both "unchanged" and "rolled back";
 *  2. the tag `<id>/<head version>` must not already exist — catches two PRs bumping to the
 *     same version in parallel, where the first merge claims the tag out from under the
 *     second. If {@see TagExistenceChecker::exists()} cannot determine this at all (network
 *     failure, missing tooling, auth failure — {@see TagExistenceCheckFailedException}),
 *     that failure is itself reported as a violation rather than treated as "tag not
 *     found": a check that fails open on tool errors would defeat condition 2 silently.
 *
 * A plugin is skipped entirely (no violation, regardless of the two conditions above) when:
 * it does not exist on the base branch (newly added by this PR), it no longer exists on the
 * PR head (removed by this PR), or none of its changed paths are published content (e.g.
 * only `tests/` changed).
 *
 * Deliberately independent of git/gh itself — {@see ManifestVersionSource} and
 * {@see TagExistenceChecker} carry that I/O, so this class is a pure function of a path list
 * plus those two lookups, fully testable without a repository (mirrors
 * {@see PrChangeChecker}'s own "pure function of a path list" design).
 */
final class VersionBumpChecker
{
    /**
     * @param string[] $changedPaths changed paths, relative to the repository root
     */
    public function check(array $changedPaths, ManifestVersionSource $manifests, TagExistenceChecker $tags): VersionBumpCheckResult
    {
        $violations = [];

        foreach (self::groupPublishedPathsByPlugin($changedPaths) as $pluginId => $paths) {
            if ($paths === []) {
                continue;
            }

            $baseVersion = $manifests->baseVersion($pluginId);
            $headVersion = $manifests->headVersion($pluginId);

            if ($baseVersion === null || $headVersion === null) {
                continue;
            }

            if (!version_compare($headVersion, $baseVersion, '>')) {
                $violations[] = new VersionBumpViolation($pluginId, \sprintf(
                    'plugins/%s/manifest.json version "%s" must be greater than the base branch\'s "%s". Published files changed: %s.',
                    $pluginId,
                    $headVersion,
                    $baseVersion,
                    implode(', ', $paths),
                ));
                continue;
            }

            try {
                $tagExists = $tags->exists($pluginId, $headVersion);
            } catch (TagExistenceCheckFailedException $e) {
                $violations[] = new VersionBumpViolation($pluginId, \sprintf(
                    'Could not verify whether tag "%s/%s" already exists (%s). Failing closed rather than '
                    .'silently accepting the version bump — re-run once the check itself can succeed.',
                    $pluginId,
                    $headVersion,
                    $e->getMessage(),
                ));
                continue;
            }

            if ($tagExists) {
                $violations[] = new VersionBumpViolation($pluginId, \sprintf(
                    'Tag "%s/%s" already exists — bump plugins/%s/manifest.json to a version that has not been released yet.',
                    $pluginId,
                    $headVersion,
                    $pluginId,
                ));
            }
        }

        return new VersionBumpCheckResult($violations);
    }

    /**
     * @param string[] $changedPaths
     *
     * @return array<string, list<string>> plugin id => its changed paths that are published
     *                                     content (i.e. not excluded by {@see PublishedContentRules})
     */
    private static function groupPublishedPathsByPlugin(array $changedPaths): array
    {
        $byPlugin = [];

        foreach ($changedPaths as $path) {
            $normalized = ltrim(trim($path), '/');
            if ($normalized === '') {
                continue;
            }

            $segments = explode('/', $normalized);
            if (($segments[0] ?? null) !== 'plugins' || !isset($segments[1]) || $segments[1] === '' || \count($segments) < 3) {
                continue;
            }

            $pluginId = $segments[1];
            $byPlugin[$pluginId] ??= [];

            if (PublishedContentRules::isExcludedRepoRelative($pluginId, $normalized) !== true) {
                $byPlugin[$pluginId][] = $path;
            }
        }

        return $byPlugin;
    }
}
