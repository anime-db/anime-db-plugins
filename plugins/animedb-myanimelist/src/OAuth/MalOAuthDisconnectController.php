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

namespace AnimeDb\Plugins\AnimedbMyanimelist\OAuth;

use AnimeDb\Plugins\AnimedbMyanimelist\Settings\SettingsFields;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Serves the "Disconnect" button from the settings page's account block: an own POST route,
 * CSRF-protected under its own token id ({@see SettingsFields::OAUTH_DISCONNECT_CSRF_TOKEN_ID}).
 *
 * Calls {@see MalOAuthClient::disconnect()} and re-renders the settings fragment, the same
 * pattern Shikimori's
 * {@see \AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthDisconnectController} uses, so
 * the HTMX swap on the settings page shows "Not authorized" immediately.
 */
#[AsController]
final class MalOAuthDisconnectController
{
    public function __construct(
        private readonly MalOAuthClient $oauth,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $submittedToken = $request->request->getString('_token');
        } catch (\Throwable) {
            return $this->renderFragment('settings.error.invalid_form');
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(SettingsFields::OAUTH_DISCONNECT_CSRF_TOKEN_ID, $submittedToken))) {
            return $this->renderFragment('settings.error.invalid_csrf');
        }

        $this->oauth->disconnect();

        return $this->renderFragment(null);
    }

    /**
     * @param ?string $error a `settings.error.*` translation key, resolved by the template
     */
    private function renderFragment(?string $error): Response
    {
        try {
            $html = $this->twig->render('@AnimedbMyanimelist/settings.html.twig', [
                'error' => $error,
                'authorized' => $this->oauth->accessToken() !== null,
            ]);
        } catch (\Throwable) {
            return new Response('<p class="error">Could not render the settings form.</p>');
        }

        return new Response($html);
    }
}
