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
 * Builds the market registry's root `plugins-registry.json` aggregate from a plugin-manifest
 * directory of this monorepo and a caller-supplied list of already-published versions.
 *
 * Deliberately does not talk to the network (no `gh release ...`, no GitHub API call) — the
 * CI-side asset collection is out of scope of this class, which only needs the resulting list
 * of `{id, version, core, sha256}` records to do its job. That keeps the class a pure function
 * of its inputs and easy to unit-test.
 *
 * Per plugin, only the *latest* full manifest (the one currently checked into
 * `plugins/<id>/manifest.json`) is embedded; older versions are represented by the compact
 * `{version, core, sha256}` triple used to resolve install/upgrade compatibility. Embedding
 * every historical manifest would make the registry grow unboundedly, since the whole file is
 * parsed into memory by the consuming application. Full manifests of older versions remain
 * available as Release assets, addressed via `asset_mirrors`.
 */
final class PluginRegistryBuilder
{
    /**
     * Canonical "vendor-name" slug shape enforced on manifest `id` by
     * {@see \AnimeDb\PluginContracts\Manifest\ManifestValidator} from `anime-db/plugin-contracts`.
     * Re-checked here on the `id` supplied via `$publishedVersions` before it is used to build a
     * filesystem path, since that input comes from outside this class and an id like
     * `../../etc/passwd` would otherwise let a caller read (and publish) an arbitrary
     * `manifest.json` outside the plugins directory.
     */
    private const PLUGIN_ID_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)+\z/';

    /**
     * URL templates for locating a version's assets, with `<id>`/`<version>`/`<file>` macros
     * for the caller to substitute. `<file>` is one of `plugin.zip` or `manifest.json`.
     *
     * Default/fallback when the caller does not supply `$assetMirrors` (see {@see self::build()}):
     * GitHub Releases only. Once mirrors are configured, the caller resolves the full list via
     * {@see AssetMirrorsResolver} (GitHub + active `MIRROR_CREDS` replicas) and passes it in
     * explicitly — this class stays a pure function of its inputs and does not itself know about
     * `MIRROR_CREDS`/`active-mirrors`.
     *
     * @var list<string>
     */
    private const ASSET_MIRRORS = [
        'https://github.com/anime-db/anime-db-plugins/releases/download/<id>/<version>/<file>',
    ];

    /**
     * `$sequence` is the monotonic anti-rollback counter (must be >= 1). The caller (CLI) is
     * responsible for deriving it from the previously published registry
     * (`previous.sequence + 1`) so that it strictly increases between generations — this method
     * only rejects non-positive values, it does not itself compare against a previous registry.
     *
     * @param list<array{id: string, version: string, core: string, sha256: string}> $publishedVersions
     * @param ?list<string>                                                          $assetMirrors      pre-resolved `asset_mirrors`
     *                                                                                                  (see {@see AssetMirrorsResolver});
     *                                                                                                  `null` falls back to
     *                                                                                                  {@see self::ASSET_MIRRORS} (GitHub only)
     *
     * @return array{
     *     sequence: int,
     *     asset_mirrors: list<string>,
     *     plugins: list<array{
     *         id: string,
     *         manifest: array<string, mixed>,
     *         versions: list<array{version: string, core: string, sha256: string}>,
     *     }>,
     * }
     */
    public function build(string $pluginsDir, array $publishedVersions, int $sequence, ?array $assetMirrors = null): array
    {
        if ($sequence < 1) {
            throw new \RuntimeException(\sprintf('Registry sequence must be a positive integer, got %d.', $sequence));
        }

        $pluginsDir = rtrim($pluginsDir, '/');

        if (!is_dir($pluginsDir)) {
            throw new \RuntimeException(\sprintf('Plugins directory "%s" does not exist.', $pluginsDir));
        }

        $versionsByPluginId = [];
        foreach ($publishedVersions as $entry) {
            $versionsByPluginId[$entry['id']][] = [
                'version' => $entry['version'],
                'core' => $entry['core'],
                'sha256' => $entry['sha256'],
            ];
        }

        $pluginIds = array_keys($versionsByPluginId);
        sort($pluginIds, \SORT_STRING);

        $plugins = [];
        foreach ($pluginIds as $pluginId) {
            $versions = $versionsByPluginId[$pluginId];
            usort(
                $versions,
                static fn (array $a, array $b): int => version_compare($b['version'], $a['version']),
            );

            $plugins[] = [
                'id' => $pluginId,
                'manifest' => $this->readManifest($pluginsDir, $pluginId),
                'versions' => $versions,
            ];
        }

        return [
            'sequence' => $sequence,
            'asset_mirrors' => $assetMirrors ?? self::ASSET_MIRRORS,
            'plugins' => $plugins,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $pluginsDir, string $pluginId): array
    {
        if (preg_match(self::PLUGIN_ID_PATTERN, $pluginId) !== 1) {
            throw new \RuntimeException(\sprintf('"%s" is not a valid plugin id. It must be a "vendor-name" slug: lowercase letters, digits and hyphen-separated segments (e.g. "vendor-name").', $pluginId));
        }

        $manifestPath = $pluginsDir.'/'.$pluginId.'/manifest.json';

        if (!is_file($manifestPath)) {
            throw new \RuntimeException(\sprintf('Published version references plugin "%s", but "%s" does not exist.', $pluginId, $manifestPath));
        }

        $json = file_get_contents($manifestPath);
        if ($json === false) {
            throw new \RuntimeException(\sprintf('Failed to read "%s".', $manifestPath));
        }

        try {
            $manifest = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(\sprintf('"%s" is not valid JSON: %s', $manifestPath, $exception->getMessage()));
        }

        if (!\is_array($manifest) || ($manifest !== [] && array_is_list($manifest))) {
            throw new \RuntimeException(\sprintf('"%s" must contain a JSON object at the top level.', $manifestPath));
        }

        return $manifest;
    }
}
