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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Settings\Fixture;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * A minimal stand-in for the host's Twig environment: real `@AnimedbShikimori` template loading
 * (against the plugin's actual `templates/` directory, not a copy) plus stub `csrf_token()`,
 * `path()` and `trans` functions/filter, the ambient host globals `settings.html.twig` and
 * `oauth_result.html.twig` rely on. The real implementations come from `symfony/twig-bridge`
 * and `symfony/translation`, which this plugin's tests deliberately do not depend on (only
 * `twig/twig` itself is a declared dev dependency) — these stand-ins only need to be callable,
 * their exact output does not matter to what these tests assert.
 *
 * The `trans` stub resolves against {@see self::CATALOG}, kept in lockstep with
 * `translations/animedb-shikimori.en.yaml` (the source-of-truth English strings), so tests can
 * keep asserting on the same display text as before i18n rather than on raw translation keys.
 */
final class StubTwigFactory
{
    /**
     * @var array<string, string> flattened `translations/animedb-shikimori.en.yaml`
     */
    private const CATALOG = [
        'settings.api_endpoint.label' => 'Shikimori API endpoint',
        'settings.api_endpoint.hint' => 'Leave empty to use the default, https://shikimori.io. Set a custom endpoint to use an alternative host.',
        'settings.save_button' => 'Save',
        'settings.saved_message' => 'Saved.',
        'settings.account.heading' => 'Shikimori account',
        'settings.account.status_authorized' => 'Authorized',
        'settings.account.status_not_authorized' => 'Not authorized',
        'settings.account.reauthorize_link' => 'Re-authorize',
        'settings.account.disconnect_button' => 'Disconnect',
        'settings.account.authorize_link' => 'Authorize',
        'settings.error.invalid_form' => 'Invalid form submission, please reload the page and try again.',
        'settings.error.invalid_csrf' => 'Invalid CSRF token, please reload the page and try again.',
        'settings.error.invalid_endpoint' => 'Enter a valid https:// URL (e.g. https://shikimori.io).',
        'settings.error.concurrent_write' => 'Settings are busy being saved elsewhere, please try again.',
        'settings.error.save_failed' => 'Could not save settings, please try again.',
        'oauth_result.page_title' => 'Shikimori authorization',
        'oauth_result.heading.success' => 'Done',
        'oauth_result.heading.failure' => 'Authorization not completed',
        'oauth_result.message.cancelled' => 'Authorization was cancelled or did not complete. You can close this tab and try again from the app.',
        'oauth_result.message.failed' => 'Could not complete Shikimori authorization. Please try again from the app.',
        'oauth_result.message.success' => 'Authorization complete. You can close this tab and return to the app.',
        'oauth_result.warning.probe_failed' => 'The token was saved, but a verification request to Shikimori did not succeed. Syncing will retry it later.',
    ];

    private function __construct()
    {
    }

    public static function create(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 3).'/templates', 'AnimedbShikimori');

        $twig = new Environment($loader);
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $tokenId): string => 'stub-csrf-token-for-'.$tokenId));
        $twig->addFunction(new TwigFunction('path', static fn (string $routeName): string => '/'.$routeName));
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $params = [], ?string $domain = null): string => self::CATALOG[$key] ?? $key));

        return $twig;
    }
}
