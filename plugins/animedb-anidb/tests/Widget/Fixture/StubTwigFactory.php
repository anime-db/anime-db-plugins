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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\Widget\Fixture;

use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * A minimal stand-in for the host's Twig environment, for widget tests: the plugin's real
 * `@AnimedbAnidb` templates, a local copy of the host's `plugin/_widget_list.html.twig` partial
 * and a `trans` filter that resolves against the plugin's real English catalog.
 */
final class StubTwigFactory
{
    private const LIST_PARTIAL = <<<'TWIG'
        <ul class="plugin-widget__list">
            {% for item in items %}
                <li><a href="{{ item.url }}"><p class="title">{{ item.title }}</p>{% if item.subtitle %}<p class="subtitle">{{ item.subtitle }}</p>{% endif %}</a></li>
            {% endfor %}
        </ul>
        TWIG;

    private function __construct()
    {
    }

    public static function create(): Environment
    {
        $files = new FilesystemLoader();
        $files->addPath(\dirname(__DIR__, 3).'/templates', 'AnimedbAnidb');
        $loader = new ChainLoader([$files, new ArrayLoader(['plugin/_widget_list.html.twig' => self::LIST_PARTIAL])]);

        $catalog = self::catalog();
        $twig = new Environment($loader);
        $twig->addFilter(new TwigFilter(
            'trans',
            static fn (string $key, array $params = [], ?string $domain = null): string => $catalog[$key] ?? $key,
        ));

        return $twig;
    }

    /**
     * @return array<string, string> flattened English catalog
     */
    public static function catalog(string $locale = 'en'): array
    {
        $data = Yaml::parseFile(\dirname(__DIR__, 3).'/translations/animedb-anidb.'.$locale.'.yaml');
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
