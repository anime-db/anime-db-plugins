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

use AnimeDb\PluginContracts\Settings\SettingsPageInterface;
use AnimeDb\Plugins\AnimedbMyanimelist\OAuth\MalOAuthClient;
use Twig\Environment;

/**
 * This plugin's whole settings page is the account block Shikimori's settings page carries
 * alongside its `api_endpoint` field — MyAnimeList's domain is fixed
 * ({@see MalOAuthClient} class doc), so there is no field to render and no settings-save route.
 *
 * {@see MalOAuthClient::accessToken()} `!== null` is rendered as "Authorized"/"Not authorized".
 * That only means a token is present, not that it is still valid — this page never refreshes it.
 *
 * Rendering only — disconnecting goes through
 * {@see \AnimeDb\Plugins\AnimedbMyanimelist\OAuth\MalOAuthDisconnectController}'s own route,
 * per {@see SettingsPageInterface}.
 */
final class MalSettingsPage implements SettingsPageInterface
{
    public function __construct(
        private readonly MalOAuthClient $oauth,
        private readonly Environment $twig,
    ) {
    }

    public function render(): string
    {
        return $this->twig->render('@AnimedbMyanimelist/settings.html.twig', [
            'error' => null,
            'authorized' => $this->oauth->accessToken() !== null,
        ]);
    }
}
