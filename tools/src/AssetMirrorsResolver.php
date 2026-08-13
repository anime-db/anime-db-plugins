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
 * Resolves the `asset_mirrors` list embedded in `plugins-registry.json` from the two inputs
 * introduced by issue #26:
 *
 *  - GitHub Releases is a hardcoded constant and always the *first* entry — it has no FTP
 *    credentials (assets are pushed there via `GH_TOKEN`, outside `MIRROR_CREDS`), is the eternal
 *    source of truth, and gives the client a deterministic fallback order (GitHub, then replicas);
 *  - every other entry comes from a `MIRROR_CREDS` credential whose id is listed in the
 *    git-tracked `active-mirrors` file — that file is what is actually reviewed and decides what
 *    gets advertised to clients, while `MIRROR_CREDS` (a secret, no PR review) only supplies
 *    coordinates for ids already vetted there.
 *
 * A `public_url` that is structurally present (see {@see MirrorCredentialsParser}) but not a
 * well-formed template — not `https://`, or missing one of the `<id>`/`<version>`/`<file>` macros
 * — is dropped from the result with a warning instead of raising an exception: `MIRROR_CREDS` is a
 * secret with no PR gate, so a typo in one replica's URL must degrade only that replica, not fail
 * the whole registry build (which would freeze every plugin's release). Same fail-open treatment
 * applies to an `active-mirrors` id with no matching `MIRROR_CREDS` entry (e.g. entry removed from
 * the secret before the id was removed from the reviewed list).
 */
final class AssetMirrorsResolver
{
    public const GITHUB_MIRROR = 'https://github.com/anime-db/anime-db-plugins/releases/download/<id>/<version>/<file>';

    /**
     * @param array<string, MirrorCredential> $mirrorCredentials keyed by mirror id, as returned by
     *                                                           {@see MirrorCredentialsParser::parse()}
     * @param list<string>                    $activeMirrorIds   as returned by {@see ActiveMirrorsFile::parse()}
     *
     * @return array{mirrors: list<string>, warnings: list<string>}
     */
    public function resolve(array $mirrorCredentials, array $activeMirrorIds): array
    {
        $mirrors = [self::GITHUB_MIRROR];
        $warnings = [];

        foreach ($activeMirrorIds as $id) {
            $credential = $mirrorCredentials[$id] ?? null;

            if ($credential === null) {
                $warnings[] = \sprintf(
                    'active-mirrors lists mirror "%s", but MIRROR_CREDS has no entry for it — skipped from asset_mirrors.',
                    $id,
                );
                continue;
            }

            if (!MirrorAssetUrl::isValidTemplate($credential->publicUrl)) {
                $warnings[] = \sprintf(
                    'Mirror "%s" has an invalid public_url "%s" (must start with "https://" and contain the <id>, <version> and <file> macros) — skipped from asset_mirrors.',
                    $id,
                    $credential->publicUrl,
                );
                continue;
            }

            $mirrors[] = $credential->publicUrl;
        }

        return ['mirrors' => $mirrors, 'warnings' => $warnings];
    }
}
