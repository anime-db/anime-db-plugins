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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Settings;

/**
 * Shared constant between {@see MalSettingsPage} (renders the account block) and
 * {@see \AnimeDb\Plugins\AnimedbMyanimelist\OAuth\MalOAuthDisconnectController} (disconnects
 * the OAuth session), so the CSRF token id can't drift apart between them.
 *
 * Unlike Shikimori's {@see \AnimeDb\Plugins\AnimedbShikimori\Settings\SettingsFields}, there is
 * no `API_ENDPOINT` key and no settings-save CSRF token id: MyAnimeList's domain is fixed, this
 * plugin has no settings-save route at all.
 */
final class SettingsFields
{
    public const OAUTH_DISCONNECT_CSRF_TOKEN_ID = 'animedb_myanimelist_oauth_disconnect';

    private function __construct()
    {
    }
}
