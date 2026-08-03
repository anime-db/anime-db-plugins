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
 * Publishes one plugin version's release assets (`plugin.zip`, `manifest.json`) to every
 * configured mirror, enforcing the two invariants issue #14 depends on:
 *
 *  - the on-mirror layout is the version-immutable tree `<id>/<version>/<file>` inside each
 *    mirror's own `dir` root, matching the `<id>/<version>/<file>` placeholders of the
 *    `asset_mirrors` URL templates in `plugins-registry.json`;
 *  - already-published files are never overwritten (idempotent re-runs) — a file is uploaded
 *    only when {@see MirrorTransport::fileExists()} reports it missing.
 *
 * Deliberately silent on ordering *with the registry*: this class only pushes assets. The
 * "assets before registry" invariant is enforced by the caller (CI) running this to completion —
 * and treating any thrown exception as "do not publish the registry" — before the separate
 * registry build/sign/commit step runs.
 */
final class MirrorAssetPublisher
{
    /**
     * Mirrors {@see PluginRegistryBuilder::PLUGIN_ID_PATTERN}: both classes turn an externally
     * supplied id into a path segment, so both guard it against traversal (e.g. "../../etc").
     */
    private const PLUGIN_ID_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)+\z/';

    /**
     * Deliberately permissive (no strict semver) since plugin manifests are not required to use
     * semver — this only rejects characters that would let a version segment escape its path
     * component (`/`, `..`) or otherwise misbehave as an FTP path segment.
     */
    private const VERSION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*\z/';

    public function __construct(
        private readonly MirrorTransport $transport,
    ) {
    }

    /**
     * @param array<string, MirrorCredential> $mirrors    as returned by
     *                                                    {@see MirrorCredentialsParser::parse()}
     * @param list<string>                    $assetFiles file names to publish, resolved
     *                                                    relative to $assetsDir
     */
    public function publish(array $mirrors, string $pluginId, string $version, string $assetsDir, array $assetFiles): void
    {
        if (preg_match(self::PLUGIN_ID_PATTERN, $pluginId) !== 1) {
            throw new \RuntimeException(\sprintf('"%s" is not a valid plugin id.', $pluginId));
        }

        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new \RuntimeException(\sprintf('"%s" is not a valid version.', $version));
        }

        $assetsDir = rtrim($assetsDir, '/');

        foreach ($assetFiles as $fileName) {
            if (!is_file($assetsDir.'/'.$fileName)) {
                throw new \RuntimeException(\sprintf('Asset "%s" does not exist in "%s".', $fileName, $assetsDir));
            }
        }

        foreach ($mirrors as $credential) {
            foreach ($assetFiles as $fileName) {
                $localPath = $assetsDir.'/'.$fileName;
                $remotePath = rtrim($credential->dir, '/')."/{$pluginId}/{$version}/{$fileName}";

                if ($this->transport->fileExists($credential, $remotePath)) {
                    continue;
                }

                $this->transport->uploadFile($credential, $localPath, $remotePath);
            }
        }
    }
}
