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
 * from "the ref itself could not be resolved" — both exit 128. `baseVersion()` tells them
 * apart from the stderr text instead, since only the former is a legitimate "plugin does
 * not exist on the base branch" ({@see BaseManifestReadFailedException} for the latter).
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
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode === 0) {
            return self::extractVersion($output === false ? '' : $output);
        }

        // `git show <ref>:<path>` reports a path missing from an otherwise-resolvable ref
        // this way — the legitimate "plugin does not exist on the base branch" case. Any
        // other failure (most notably an unresolvable ref, e.g. a shallow clone that never
        // fetched the base commit) means the base version genuinely could not be read and
        // must not be silently treated as "no base version".
        if ($errorOutput !== false && str_contains($errorOutput, 'does not exist in')) {
            return null;
        }

        throw new BaseManifestReadFailedException(\sprintf('git show %s:%s exited with code %d: %s', $this->baseRef, $path, $exitCode, trim($errorOutput === false ? '' : $errorOutput)));
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
