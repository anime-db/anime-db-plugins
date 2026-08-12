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

namespace AnimeDb\Plugins\AnimedbShikimori\Settings;

use AnimeDb\PluginContracts\Settings\SettingsPageInterface;
use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthClient;
use Twig\Environment;

/**
 * Phase 2's settings page: a single `api_endpoint` field. Shikimori's domain has migrated before
 * (`.org` -> `.one` -> `.io`), so a user may need to point the plugin at an alternative host;
 * {@see \AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlClient} already reads
 * this key and falls back to the default host when it is absent.
 *
 * Phase 3 adds the OAuth status: {@see ShikimoriOAuthClient::accessToken()} `!== null` is
 * rendered as "Authorized"/"Not authorized". That only means a token is present, not that it
 * is still valid — the access token lives about a day and this page never refreshes it; phase
 * 4's sync is the first thing that calls {@see ShikimoriOAuthClient::refreshAccessToken()} or
 * reacts to a 401.
 *
 * Rendering only — saving goes through {@see ShikimoriSettingsController}'s own route, and
 * disconnecting through {@see \AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthDisconnectController}'s,
 * per {@see SettingsPageInterface}.
 */
final class ShikimoriSettingsPage implements SettingsPageInterface
{
    public function __construct(
        private readonly SettingsStoreInterface $settings,
        private readonly ShikimoriOAuthClient $oauth,
        private readonly Environment $twig,
    ) {
    }

    public function render(): string
    {
        $endpoint = $this->settings->read()[SettingsFields::API_ENDPOINT] ?? null;

        return $this->twig->render('@AnimedbShikimori/settings.html.twig', [
            'apiEndpoint' => \is_string($endpoint) ? $endpoint : '',
            'saved' => false,
            'error' => null,
            'authorized' => $this->oauth->accessToken() !== null,
        ]);
    }
}
