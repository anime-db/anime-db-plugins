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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Tests\OAuth\Fixture;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * A minimal stand-in for the host's Twig environment: real `@AnimedbMyanimelist` template
 * loading (against the plugin's actual `templates/` directory, not a copy) plus a stub
 * `trans` filter — `oauth_result.html.twig` relies on it. The real implementation comes from
 * `symfony/translation`, which this plugin's tests deliberately do not depend on (only
 * `twig/twig` itself is a declared dev dependency).
 *
 * The `trans` stub resolves against {@see self::CATALOG}, kept in lockstep with
 * `translations/animedb-myanimelist.en.yaml` (the source-of-truth English strings), so tests
 * can assert on the same display text as before i18n rather than on raw translation keys.
 */
final class StubTwigFactory
{
    /**
     * @var array<string, string> flattened `translations/animedb-myanimelist.en.yaml`
     */
    private const CATALOG = [
        'oauth_result.page_title' => 'MyAnimeList authorization',
        'oauth_result.heading.success' => 'Done',
        'oauth_result.heading.failure' => 'Authorization not completed',
        'oauth_result.message.cancelled' => 'Authorization was cancelled or did not complete. You can close this tab and try again from the app.',
        'oauth_result.message.failed' => 'Could not complete MyAnimeList authorization. Please try again from the app.',
        'oauth_result.message.success' => 'Authorization complete. You can close this tab and return to the app.',
        'oauth_result.warning.probe_failed' => 'The token was saved, but a verification request to MyAnimeList did not succeed. Syncing will retry it later.',
    ];

    private function __construct()
    {
    }

    public static function create(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 3).'/templates', 'AnimedbMyanimelist');

        $twig = new Environment($loader);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $params = [], ?string $domain = null): string => self::CATALOG[$key] ?? $key));

        return $twig;
    }
}
