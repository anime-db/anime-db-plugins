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
 * Activates/backfills one mirror from GitHub Releases (issue #26): re-projects every historical
 * plugin version onto the target mirror over FTP(S), HEAD-verifies each uploaded asset, and only
 * on FULL success is the mirror considered ready to be listed in `active-mirrors`. Also doubles as
 * self-healing for a mirror that has drifted (been pruned/wiped) — re-running it re-uploads
 * everything, and the overwrite semantics of {@see MirrorAssetPublisher} make that safe.
 *
 * Unlike a release push, where a single mirror's flaky HEAD must not block publishing (see
 * {@see MirrorAssetReachabilityVerifier}'s caller in `push-mirror-assets.php`), backfill treats
 * ANY unreachable asset as fatal: an incompletely or unreachably mirrored history must never be
 * advertised to clients, and this is not a release under time pressure — failing loudly here is
 * safe and cheap to retry.
 *
 * Deliberately does not touch `active-mirrors` or git itself — same separation of concerns as
 * every other tool in this directory (e.g. `push-mirror-registry.php` uploads but never commits):
 * the caller (CI) decides what "on success" means for the git-tracked file.
 */
final class MirrorBackfillPublisher
{
    public function __construct(
        private readonly ReleaseAssetSource $source,
        private readonly MirrorAssetPublisher $publisher,
        private readonly MirrorAssetReachabilityVerifier $verifier,
    ) {
    }

    /**
     * @throws \RuntimeException on the first release that fails to download, upload, or
     *                           HEAD-verify — the mirror must not be considered backfilled
     */
    public function backfill(MirrorCredential $mirror): void
    {
        if (!MirrorAssetUrl::isValidTemplate($mirror->publicUrl)) {
            throw new \RuntimeException(\sprintf('Mirror "%s" has an invalid public_url "%s" — refusing to backfill.', $mirror->id, $mirror->publicUrl));
        }

        $mirrors = [$mirror->id => $mirror];

        foreach ($this->source->listReleases() as $release) {
            $tempDir = sys_get_temp_dir().'/mirror-backfill-'.bin2hex(random_bytes(8));
            mkdir($tempDir);

            try {
                $downloaded = $this->source->downloadAssets($release['id'], $release['version'], $tempDir);

                foreach (['plugin.zip', 'manifest.json'] as $required) {
                    if (!\in_array($required, $downloaded, true)) {
                        throw new \RuntimeException(\sprintf('Release "%s/%s" is missing the "%s" asset — refusing to backfill mirror "%s".', $release['id'], $release['version'], $required, $mirror->id));
                    }
                }

                $this->publisher->publish($mirrors, $release['id'], $release['version'], $tempDir, ['plugin.zip', 'manifest.json']);

                $reports = $this->verifier->verify($mirrors, $release['id'], $release['version'], ['plugin.zip', 'manifest.json']);
                foreach ($reports as $report) {
                    if (!$report->reachable) {
                        throw new \RuntimeException(\sprintf('"%s" is not reachable after uploading to mirror "%s" — refusing to activate it.', $report->url, $mirror->id));
                    }
                }
            } finally {
                foreach (['plugin.zip', 'manifest.json'] as $file) {
                    if (is_file($tempDir.'/'.$file)) {
                        unlink($tempDir.'/'.$file);
                    }
                }
                rmdir($tempDir);
            }
        }
    }
}
