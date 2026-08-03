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
 * One mirror's write credentials, as decoded from a single entry of the `MIRROR_CREDS` GitHub
 * Actions secret (see {@see MirrorCredentialsParser}). Deliberately holds only the *secret* half
 * of a mirror's identity — the *public* half (the download URL template reachable by clients) is
 * `asset_mirrors` in `plugins-registry.json`, checked into git; the two are linked only by a
 * human matching this credential's `id` to the right `asset_mirrors` entry, not by any field
 * shared between the two.
 */
final class MirrorCredential
{
    public function __construct(
        public readonly string $id,
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly string $password,
        public readonly string $dir,
        public readonly string $protocol,
    ) {
    }
}
