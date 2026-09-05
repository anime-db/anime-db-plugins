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
 * Real {@see ManifestVersionSource}. The base version is read via `git show <ref>:path`
 * (no working-tree checkout of the base branch needed); the head version is read straight
 * off the working tree, which the caller is expected to already have checked out at the
 * PR's head commit (true for the `pr-validation.yml` gate, which checks out `head.sha`).
 *
 * `git show`'s exit code alone does not distinguish "the path does not exist at that ref"
 * from "the ref itself could not be resolved" — both exit 128, and `git show`'s stderr text
 * for the two collapses to the same message whenever the path also exists on disk (always
 * true here, since the caller has the PR head checked out): `baseVersion()` cannot use it to
 * tell them apart. Instead it resolves the ref first via `git rev-parse --verify --quiet`,
 * which reports ref resolvability alone, with no path involved. Only once the ref is known to
 * resolve does a subsequent `git show` failure legitimately mean "plugin does not exist on
 * the base branch"; an unresolvable ref throws {@see BaseManifestReadFailedException} before
 * `git show` is even run.
 */
final class GitManifestVersionSource implements ManifestVersionSource
{
    public function __construct(
        private readonly string $baseRef,
        private readonly string $repoRoot,
    ) {
    }

    public function baseVersion(string $pluginId): ?string
    {
        $path = 'plugins/'.$pluginId.'/manifest.json';

        $this->assertBaseRefResolves();

        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            ['git', 'show', $this->baseRef.':'.$path],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException(\sprintf('Failed to spawn "git show %s:%s".', $this->baseRef, $path));
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode === 0) {
            return self::extractVersion($output === false ? '' : $output);
        }

        // The base ref is already known to resolve (checked above), so a `git show` failure
        // here means the path itself is absent from that ref — the legitimate "plugin does
        // not exist on the base branch" case (a plugin newly added by this PR).
        return null;
    }

    /**
     * @throws BaseManifestReadFailedException when {@see $baseRef} does not resolve to a
     *                                         commit at all — e.g. a shallow clone that
     *                                         never fetched it
     */
    private function assertBaseRefResolves(): void
    {
        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            ['git', 'rev-parse', '--verify', '--quiet', $this->baseRef.'^{commit}'],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException(\sprintf('Failed to spawn "git rev-parse --verify %s^{commit}".', $this->baseRef));
        }

        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new BaseManifestReadFailedException(\sprintf('git rev-parse --verify %s^{commit} exited with code %d: %s', $this->baseRef, $exitCode, trim($errorOutput === false ? '' : $errorOutput)));
        }
    }

    public function headVersion(string $pluginId): ?string
    {
        $path = $this->repoRoot.'/plugins/'.$pluginId.'/manifest.json';

        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return $content === false ? null : self::extractVersion($content);
    }

    private static function extractVersion(string $manifestJson): ?string
    {
        $data = json_decode($manifestJson, true);

        return \is_array($data) && isset($data['version']) && \is_string($data['version']) ? $data['version'] : null;
    }
}
