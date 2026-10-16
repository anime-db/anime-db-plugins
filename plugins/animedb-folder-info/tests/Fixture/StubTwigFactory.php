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

namespace AnimeDb\Plugins\AnimedbFolderInfo\Tests\Fixture;

use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * A minimal stand-in for the host's Twig environment: the plugin's real templates plus stub
 * `csrf_token()` / `path()` and a `trans` filter resolving against the plugin's real catalog
 * of the requested locale; keys of the application's `messages` domain come back as `app:<key>`.
 */
final class StubTwigFactory
{
    private function __construct()
    {
    }

    public static function create(string $locale = 'en'): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2).'/templates', 'AnimedbFolderInfo');

        $catalog = self::catalog($locale);
        $twig = new Environment($loader);
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $tokenId): string => 'stub-csrf-token-for-'.$tokenId));
        $twig->addFunction(new TwigFunction('path', static fn (string $route, array $params = []): string => '/'.$route.'/'.implode('/', $params)));
        $twig->addFilter(new TwigFilter(
            'trans',
            static fn (string $key, array $params = [], ?string $domain = null): string => match ($domain) {
                'animedb-folder-info' => $catalog[$key] ?? $key,
                'messages', null => 'app:'.$key,
                default => $key,
            },
        ));

        return $twig;
    }

    /**
     * @return array<string, string> flattened catalog
     */
    public static function catalog(string $locale): array
    {
        $data = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/animedb-folder-info.'.$locale.'.yaml');
        \assert(\is_array($data));

        $flat = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$flat): void {
            foreach ($node as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (\is_array($value)) {
                    $walk($value, $path);
                } else {
                    $flat[$path] = (string) $value;
                }
            }
        };
        $walk($data, '');

        return $flat;
    }
}
