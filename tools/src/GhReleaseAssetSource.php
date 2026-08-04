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

namespace AnimeDb\Plugins\Tools;

/**
 * Real {@see ReleaseAssetSource}, backed by shelling out to the `gh` CLI (same tool already used
 * by release.yml/registry.yml). Requires `gh` on PATH and authenticated (`GH_TOKEN`/`GITHUB_TOKEN`
 * in the environment, as GitHub Actions sets up automatically).
 *
 * Only tags shaped `<plugin-id>/<version>` are treated as plugin releases — matches the
 * convention `release.yml` publishes under and the skip-with-warning behaviour `registry.yml`
 * already applies to foreign tags.
 */
final class GhReleaseAssetSource implements ReleaseAssetSource
{
    public function listReleases(): array
    {
        $output = $this->run('gh release list --limit 1000 --json tagName --jq \'.[].tagName\'');

        $releases = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $tag) {
            $tag = trim($tag);
            if ($tag === '' || !str_contains($tag, '/')) {
                continue;
            }

            [$id, $version] = explode('/', $tag, 2);
            $releases[] = ['id' => $id, 'version' => $version];
        }

        return $releases;
    }

    public function downloadAssets(string $pluginId, string $version, string $destDir): array
    {
        $tag = $pluginId.'/'.$version;

        $this->run(\sprintf(
            'gh release download %s --dir %s --clobber --pattern %s --pattern %s',
            escapeshellarg($tag),
            escapeshellarg($destDir),
            escapeshellarg('plugin.zip'),
            escapeshellarg('manifest.json'),
        ));

        $files = [];
        foreach (['plugin.zip', 'manifest.json'] as $file) {
            if (is_file($destDir.'/'.$file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function run(string $command): string
    {
        exec($command.' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(\sprintf('Command failed (exit %d): %s'."\n%s", $exitCode, $command, implode("\n", $output)));
        }

        return implode("\n", $output);
    }
}
