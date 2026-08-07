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

use AnimeDb\PluginContracts\Settings\ConcurrentWriteException;
use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Saves the `api_endpoint` field from {@see ShikimoriSettingsPage}'s form. This is the plugin's
 * first own route (`plugin-routing.yaml` at the plugin root, POST /plugins/animedb-shikimori/settings)
 * and the pattern OAuth (phase 3) will reuse.
 *
 * Unlike the settings page's GET render(), which the host wraps in a try/catch
 * ({@see \AnimeDb\PluginContracts\Settings\SettingsPageInterface}), this save route is not
 * wrapped by the host at all — every failure path here (bad CSRF token, an implausible
 * endpoint, {@see SettingsStoreInterface::update()} throwing, even the fragment itself failing to
 * render) is caught and turned into an inline error response, never a raw 500 or a response HTMX
 * can't swap.
 *
 * `api_endpoint` validation is deliberately shallow (scheme + host only, not an SSRF guard —
 * this is trusted, single-user input): its only purpose is to catch an obviously broken value
 * before it is stored, because {@see \AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlClient}
 * swallows request failures into an empty filled card during a bulk scan, so a bad endpoint
 * would otherwise fail silently far away from this form.
 *
 * Saving goes through {@see SettingsStoreInterface::update()}: the modifier closure merges
 * `api_endpoint` into whatever payload the host hands it and returns the whole thing, rather
 * than replacing it — dropping every other stored key (OAuth tokens, from phase 3, among them)
 * would otherwise be one accidental `return` away. update() runs the closure under the host's
 * exclusive lock on this plugin's settings, closing the read-then-write race a second writer
 * (phase 3's background token rotation) could otherwise land in.
 */
#[AsController]
final class ShikimoriSettingsController
{
    public function __construct(
        private readonly SettingsStoreInterface $settings,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $submittedToken = $request->request->getString('_token');
            $endpoint = trim($request->request->getString(SettingsFields::API_ENDPOINT));
        } catch (\Throwable) {
            return $this->renderFragment(null, saved: false, error: 'Invalid form submission, please reload the page and try again.');
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(SettingsFields::CSRF_TOKEN_ID, $submittedToken))) {
            return $this->renderFragment(null, saved: false, error: 'Invalid CSRF token, please reload the page and try again.');
        }

        if ($endpoint !== '' && !self::isPlausibleHttpsUrl($endpoint)) {
            return $this->renderFragment($endpoint, saved: false, error: 'Enter a valid https:// URL (e.g. https://shikimori.io).');
        }

        try {
            $this->settings->update(static function (array $settings) use ($endpoint): array {
                if ($endpoint === '') {
                    unset($settings[SettingsFields::API_ENDPOINT]);
                } else {
                    $settings[SettingsFields::API_ENDPOINT] = $endpoint;
                }

                return $settings;
            });
        } catch (ConcurrentWriteException) {
            return $this->renderFragment($endpoint, saved: false, error: 'Settings are busy being saved elsewhere, please try again.');
        } catch (\Throwable) {
            return $this->renderFragment($endpoint, saved: false, error: 'Could not save settings, please try again.');
        }

        return $this->renderFragment($endpoint === '' ? null : $endpoint, saved: true, error: null);
    }

    private function renderFragment(?string $apiEndpoint, bool $saved, ?string $error): Response
    {
        try {
            $html = $this->twig->render('@AnimedbShikimori/settings.html.twig', [
                'apiEndpoint' => $apiEndpoint ?? '',
                'saved' => $saved,
                'error' => $error,
            ]);
        } catch (\Throwable) {
            return new Response('<p class="error">Could not render the settings form.</p>');
        }

        return new Response($html);
    }

    private static function isPlausibleHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return \is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && \is_string($parts['host'] ?? null)
            && $parts['host'] !== '';
    }
}
