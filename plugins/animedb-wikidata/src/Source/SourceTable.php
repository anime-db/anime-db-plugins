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
 * The declarative table of links the plugin hands out (`resources/sources.json`): one row, one
 * source. Adding a source is adding a row with its `example`; the code does not change.
 *
 * A row has `name`, exactly one of `property` (`P4086`) or `sitelink` (`ruwiki`), `url` (a template
 * with `{id}`; required for a property, absent for a sitelink whose address comes from the answer),
 * `id` (a regular expression without delimiters for the accepted value), `catalog` (an anime catalog:
 * it takes part in the "no catalog, no link" rule and in the refusal of `resolveExternalId()`) and
 * `example` (`{"id": …, "url": …}`; the template applied to `example.id` must give `example.url`).
 */
final class SourceTable
{
    /**
     * @param list<Source> $sources
     */
    private function __construct(private readonly array $sources)
    {
    }

    public static function default(): self
    {
        return self::fromFile(__DIR__.'/../../resources/sources.json');
    }

    public static function fromFile(string $path): self
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException(\sprintf('Cannot read the source table "%s".', $path));
        }
        try {
            $rows = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The source table is not valid JSON.', 0, $exception);
        }

        return self::fromArray($rows);
    }

    /**
     * @throws \InvalidArgumentException when a row is invalid
     */
    public static function fromArray(mixed $rows): self
    {
        if (!\is_array($rows) || !array_is_list($rows) || $rows === []) {
            throw new \InvalidArgumentException('The source table must be a non-empty list of rows.');
        }

        $sources = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $source = self::parseRow($row, $index);
            $key = (string) ($source->property ?? $source->sitelink);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(\sprintf('Source "%s" is listed twice.', $key));
            }
            $seen[$key] = true;
            $sources[] = $source;
        }

        return new self($sources);
    }

    /**
     * @return list<Source>
     */
    public function all(): array
    {
        return $this->sources;
    }

    /**
     * Hosts (lowercase, without `www.`) of the anime-catalog rows, taken from their url templates.
     *
     * @return list<string>
     */
    public function catalogHosts(): array
    {
        $hosts = [];
        foreach ($this->sources as $source) {
            if (!$source->catalog || $source->urlTemplate === null) {
                continue;
            }
            $host = parse_url(str_replace('{id}', 'x', $source->urlTemplate), \PHP_URL_HOST);
            if (\is_string($host)) {
                $hosts[] = (string) preg_replace('/^www\./', '', strtolower($host));
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function parseRow(mixed $row, int $index): Source
    {
        $where = \sprintf('Source table row #%d', $index);
        if (!\is_array($row)) {
            throw new \InvalidArgumentException($where.' must be an object.');
        }

        $name = $row['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            throw new \InvalidArgumentException($where.' needs a "name".');
        }
        $where .= \sprintf(' ("%s")', $name);

        $property = $row['property'] ?? null;
        $sitelink = $row['sitelink'] ?? null;
        if (($property === null) === ($sitelink === null)) {
            throw new \InvalidArgumentException($where.' needs exactly one of "property" and "sitelink".');
        }
        if ($property !== null && (!\is_string($property) || preg_match('/^P[1-9]\d*\z/', $property) !== 1)) {
            throw new \InvalidArgumentException($where.' has an invalid "property".');
        }
        if ($sitelink !== null && (!\is_string($sitelink) || preg_match('/^[a-z_]+wiki\z/', $sitelink) !== 1)) {
            throw new \InvalidArgumentException($where.' has an invalid "sitelink".');
        }

        $url = $row['url'] ?? null;
        if ($property !== null && (!\is_string($url) || !str_contains($url, '{id}'))) {
            throw new \InvalidArgumentException($where.' needs a "url" with {id}.');
        }
        if ($sitelink !== null && $url !== null) {
            throw new \InvalidArgumentException($where.' is a sitelink and must not have a "url".');
        }

        $pattern = $row['id'] ?? null;
        if (!\is_string($pattern) || $pattern === '' || @preg_match(Source::delimit($pattern), '') === false) {
            throw new \InvalidArgumentException($where.' needs a compilable "id" pattern.');
        }

        $catalog = $row['catalog'] ?? null;
        if (!\is_bool($catalog)) {
            throw new \InvalidArgumentException($where.' needs a boolean "catalog".');
        }

        $example = $row['example'] ?? null;
        if (!\is_array($example) || !\is_string($example['id'] ?? null) || !\is_string($example['url'] ?? null)) {
            throw new \InvalidArgumentException($where.' needs an "example" with "id" and "url".');
        }

        $source = new Source($name, $property, $sitelink, $url, $pattern, $catalog, $example['id'], $example['url']);
        if (!$source->acceptsId($example['id']) || $source->buildUrl($example['id']) !== $example['url']) {
            throw new \InvalidArgumentException($where.' has an "example" that its own rules do not reproduce.');
        }

        return $source;
    }
}
