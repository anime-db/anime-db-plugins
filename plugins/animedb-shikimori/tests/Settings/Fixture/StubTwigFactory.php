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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Settings\Fixture;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * A minimal stand-in for the host's Twig environment: real `@AnimedbShikimori` template loading
 * (against the plugin's actual `templates/` directory, not a copy) plus stub `csrf_token()` and
 * `path()` functions, the two ambient host globals `settings.html.twig` relies on. The real
 * implementations come from `symfony/twig-bridge`, which this plugin's tests deliberately do not
 * depend on (only `twig/twig` itself is a declared dev dependency) — these stand-ins only need to
 * be callable, their exact output does not matter to what these tests assert.
 */
final class StubTwigFactory
{
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

        return $twig;
    }
}
