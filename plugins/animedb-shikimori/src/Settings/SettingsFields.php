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

namespace AnimeDb\Plugins\AnimedbShikimori\Settings;

/**
 * Shared constants between {@see ShikimoriSettingsPage} (renders the form) and
 * {@see ShikimoriSettingsController} (saves it) / {@see \AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthDisconnectController}
 * (disconnects the OAuth session), so the settings key and CSRF token ids can't drift apart
 * between them.
 */
final class SettingsFields
{
    public const API_ENDPOINT = 'api_endpoint';
    public const CSRF_TOKEN_ID = 'animedb_shikimori_settings_save';

    /**
     * A CSRF token id of its own, distinct from {@see self::CSRF_TOKEN_ID}: the disconnect
     * form is a separate action from the settings save form and must not accept a token minted
     * for the other.
     */
    public const OAUTH_DISCONNECT_CSRF_TOKEN_ID = 'animedb_shikimori_oauth_disconnect';

    private function __construct()
    {
    }
}
