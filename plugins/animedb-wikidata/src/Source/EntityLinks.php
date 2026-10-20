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
 * Turns an entity of the Wikidata API into links by the source table: pure, no network.
 *
 * Value selection for a property: `deprecated` statements are dropped; if a `preferred` one is
 * left, only preferred ones are kept; if more than one value is left, the whole property is
 * skipped (a series has one MAL id per season, and the first one would be a guess); a value that
 * does not fit the row's `id` pattern is skipped. A sitelink is single per language.
 */
final class EntityLinks
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $entity
     *
     * @return array{urls: list<string>, catalog: bool} urls in the order of the table; catalog is
     *                                                   true when an anime-catalog row gave a link
     */
    public static function collect(SourceTable $table, array $entity): array
    {
        $urls = [];
        $catalog = false;

        foreach ($table->all() as $source) {
            $value = $source->property !== null
                ? self::propertyValue($entity, $source->property)
                : self::sitelinkUrl($entity, (string) $source->sitelink);
            if ($value === null || !$source->acceptsId($value)) {
                continue;
            }
            $url = $source->buildUrl($value);
            if ($url === null) {
                continue;
            }
            $urls[] = $url;
            $catalog = $catalog || $source->catalog;
        }

        return ['urls' => $urls, 'catalog' => $catalog];
    }

    /**
     * @param array<string, mixed> $entity
     */
    private static function propertyValue(array $entity, string $property): ?string
    {
        $claims = $entity['claims'][$property] ?? null;
        if (!\is_array($claims)) {
            return null;
        }

        $normal = [];
        $preferred = [];
        foreach ($claims as $claim) {
            if (!\is_array($claim)) {
                continue;
            }
            $rank = $claim['rank'] ?? 'normal';
            if ($rank === 'deprecated') {
                continue;
            }
            $snak = $claim['mainsnak'] ?? null;
            $value = \is_array($snak) && ($snak['snaktype'] ?? null) === 'value' ? ($snak['datavalue']['value'] ?? null) : null;
            if (!\is_string($value)) {
                // a statement we cannot read still counts as a value: skipping it silently could
                // pass off one of several values as the only one
                $value = '';
            }
            if ($rank === 'preferred') {
                $preferred[] = $value;
            } else {
                $normal[] = $value;
            }
        }

        $values = $preferred !== [] ? $preferred : $normal;

        return \count($values) === 1 && $values[0] !== '' ? $values[0] : null;
    }

    /**
     * @param array<string, mixed> $entity
     */
    private static function sitelinkUrl(array $entity, string $site): ?string
    {
        $url = $entity['sitelinks'][$site]['url'] ?? null;

        return \is_string($url) ? $url : null;
    }
}
