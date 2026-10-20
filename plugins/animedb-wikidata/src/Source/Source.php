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

namespace AnimeDb\Plugins\AnimedbWikidata\Source;

/**
 * One row of the source table (`resources/sources.json`): where a link comes from in an item
 * and how it is built. See {@see SourceTable}.
 */
final class Source
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $property,
        public readonly ?string $sitelink,
        public readonly ?string $urlTemplate,
        public readonly string $idPattern,
        public readonly bool $catalog,
        public readonly string $exampleId,
        public readonly string $exampleUrl,
    ) {
    }

    public function acceptsId(string $value): bool
    {
        return preg_match(self::delimit($this->idPattern), $value) === 1;
    }

    /**
     * The absolute http(s) address for an accepted value, or null when the result is not one.
     */
    public function buildUrl(string $value): ?string
    {
        $url = $this->urlTemplate === null ? $value : str_replace('{id}', $value, $this->urlTemplate);
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($scheme) && preg_match('/^https?$/i', $scheme) === 1 && \is_string($host) && $host !== '' ? $url : null;
    }

    public static function delimit(string $pattern): string
    {
        return '#'.str_replace('#', '\#', $pattern).'#u';
    }
}
