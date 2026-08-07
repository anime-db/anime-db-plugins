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

namespace AnimeDb\Plugins\AnimedbShikimori\OAuth;

use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use AnimeDb\Plugins\AnimedbShikimori\Settings\SettingsFields;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Saves the "Disconnect" button from the settings page's OAuth section: an own POST route,
 * CSRF-protected the same way as {@see \AnimeDb\Plugins\AnimedbShikimori\Settings\ShikimoriSettingsController}
 * but under its own token id ({@see SettingsFields::OAUTH_DISCONNECT_CSRF_TOKEN_ID}), so a
 * token minted for the settings-save form cannot be replayed here or vice versa.
 *
 * Calls {@see ShikimoriOAuthClient::disconnect()} (available since plugin-contracts v0.11.0)
 * and re-renders the settings fragment, the same pattern the save route uses, so the HTMX
 * swap on the settings page shows "Not authorized" immediately.
 */
#[AsController]
final class ShikimoriOAuthDisconnectController
{
    public function __construct(
        private readonly SettingsStoreInterface $settings,
        private readonly ShikimoriOAuthClient $oauth,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $submittedToken = $request->request->getString('_token');
        } catch (\Throwable) {
            return $this->renderFragment('Invalid form submission, please reload the page and try again.');
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(SettingsFields::OAUTH_DISCONNECT_CSRF_TOKEN_ID, $submittedToken))) {
            return $this->renderFragment('Invalid CSRF token, please reload the page and try again.');
        }

        $this->oauth->disconnect();

        return $this->renderFragment(null);
    }

    private function renderFragment(?string $error): Response
    {
        $endpoint = $this->settings->read()[SettingsFields::API_ENDPOINT] ?? null;

        try {
            $html = $this->twig->render('@AnimedbShikimori/settings.html.twig', [
                'apiEndpoint' => \is_string($endpoint) ? $endpoint : '',
                'saved' => false,
                'error' => $error,
                'authorized' => $this->oauth->accessToken() !== null,
            ]);
        } catch (\Throwable) {
            return new Response('<p class="error">Could not render the settings form.</p>');
        }

        return new Response($html);
    }
}
