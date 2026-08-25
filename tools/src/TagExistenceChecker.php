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
 * Tells {@see VersionBumpChecker} whether a plugin release tag `<id>/<version>` already
 * exists — the same tag shape `release.yml` publishes under. A second open PR bumping to a
 * version another, already-merged PR already claimed must fail this check, or its content
 * is silently never published (`release.yml` skips a tag that already has a release).
 */
interface TagExistenceChecker
{
    /**
     * @throws TagExistenceCheckFailedException when the check itself failed (network,
     *                                          missing tooling, auth) — never conflate
     *                                          this with "the tag was not found"
     */
    public function exists(string $pluginId, string $version): bool;
}
