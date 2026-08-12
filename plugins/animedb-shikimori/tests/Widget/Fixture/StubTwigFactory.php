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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Widget\Fixture;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * A minimal stand-in for the host's Twig environment, for widget tests: real
 * `@AnimedbShikimori` template loading (against the plugin's actual `templates/` directory) plus
 * the default namespace pointed at a local fixture standing in for the host's
 * `templates/plugin/_widget_list.html.twig` (see {@see host_templates}), since that partial
 * belongs to `anime-db-desktop`, not this repository.
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
        $loader->addPath(__DIR__.'/host_templates');

        return new Environment($loader);
    }
}
