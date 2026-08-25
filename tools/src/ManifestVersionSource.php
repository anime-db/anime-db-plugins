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
 * Resolves a plugin's `manifest.json` `version` on the two points {@see VersionBumpChecker}
 * compares: the PR's base branch and its head. Kept as an interface (mirrors
 * {@see ReleaseAssetSource}, {@see MirrorTransport}) so the checker's own logic is testable
 * without a real git checkout.
 */
interface ManifestVersionSource
{
    /**
     * @return string|null the base-branch version, or null if the plugin's manifest.json
     *                     does not exist there at all (a plugin newly added by this PR)
     */
    public function baseVersion(string $pluginId): ?string;

    /**
     * @return string|null the PR-head version, or null if the plugin's manifest.json does
     *                     not exist there (a plugin this PR removes entirely)
     */
    public function headVersion(string $pluginId): ?string;
}
