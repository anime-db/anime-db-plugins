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
 * Source of historical plugin releases for {@see MirrorBackfillPublisher}, abstracted away from
 * the real GitHub Releases lookup ({@see GhReleaseAssetSource}) so the backfill orchestration can
 * be unit-tested without a real `gh` CLI / network call.
 *
 * GitHub Releases is always the source for a backfill (issue #26's "star topology": GitHub is the
 * hub, mirrors are spokes) — never another mirror over FTP — so read credentials for mirrors are
 * never needed, only `GH_TOKEN`.
 */
interface ReleaseAssetSource
{
    /**
     * @return list<array{id: string, version: string}>
     */
    public function listReleases(): array;

    /**
     * Downloads that release's `plugin.zip` and `manifest.json` assets into $destDir.
     *
     * @return list<string> file names actually present in $destDir after the download
     */
    public function downloadAssets(string $pluginId, string $version, string $destDir): array;
}
