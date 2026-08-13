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
 * One mirror's credentials, as decoded from a single entry of the `MIRROR_CREDS` GitHub Actions
 * secret (see {@see MirrorCredentialsParser}). Holds both halves of a mirror's identity: the
 * *write* half (`host`/`port`/`user`/`password`/`dir`/`protocol`, used to push assets/registry via
 * FTP(S)) and the *public* half (`publicUrl`, the download URL template reachable by clients, e.g.
 * `https://mirror.tld/plugins/<id>/<version>/<file>`). Keeping both in the one secret entry is
 * deliberate (issue #26): before, `publicUrl` lived only as a hardcoded, unrelated
 * `PluginRegistryBuilder::ASSET_MIRRORS` entry, so adding a mirror meant editing two disconnected
 * places and risked publishing write credentials for a mirror clients were never told about (or
 * vice versa). This `id` still has to be matched by a human against a git-tracked
 * `active-mirrors` entry before `publicUrl` is advertised in `asset_mirrors` — see
 * {@see AssetMirrorsResolver} — so a MIRROR_CREDS entry existing is not by itself enough to go
 * live.
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
        public readonly string $publicUrl,
    ) {
    }
}
