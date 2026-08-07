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

namespace AnimeDb\Plugins\AnimedbShikimori\Http;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;

/**
 * Builds the `AnimeDB <id>/<version> (+https://anime-db.org/)` User-Agent string Shikimori
 * requires on every request (anonymous GraphQL reads and OAuth token requests alike) or it
 * bans the caller's IP.
 *
 * Shared by {@see \AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthClient} and
 * {@see \AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriTokenProbe}. {@see GraphQlClient}
 * keeps its own inline copy of the same format for now (phase 3's blast radius is
 * deliberately kept to the new OAuth surface) — migrating the filler onto this helper is a
 * follow-up, not part of this change.
 */
final class UserAgent
{
    private const FORMAT = 'AnimeDB %s/%s (+https://anime-db.org/)';

    private function __construct()
    {
    }

    public static function forManifest(OwnManifestInterface $manifest): string
    {
        return \sprintf(self::FORMAT, $manifest->id(), $manifest->version());
    }
}
