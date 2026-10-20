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

namespace AnimeDb\Plugins\AnimedbWikidata;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\Plugins\AnimedbWikidata\ExternalId\WikidataIdResolver;
use AnimeDb\Plugins\AnimedbWikidata\Http\WikidataClient;
use AnimeDb\Plugins\AnimedbWikidata\Source\EntityLinks;
use AnimeDb\Plugins\AnimedbWikidata\Source\SourceTable;

/**
 * The linking bridge through Wikidata: recognizes the links of a record that has no anime-catalog
 * links (see {@see WikidataIdResolver}) and fills only its `externalUrls` from the source table
 * (`resources/sources.json`). It is not a source of metadata, nor a Kinopoisk or IMDb plugin.
 *
 * The plugin id of a record is a search key: `Q…`, `imdb:tt…` or `kinopoisk:N`. It does not change
 * after the first linking, and `findById()` accepts all three forms.
 */
final class WikidataBridge implements FillerInterface
{
    private const PROPERTY_HAS_PARTS = 'P527';

    private SourceTable $sources;

    /**
     * The public constructor takes only autowirable services: the host registers every class
     * of the plugin as a service, and `SourceTable` has a private constructor.
     */
    public function __construct(
        private readonly WikidataClient $client,
    ) {
        $this->sources = SourceTable::default();
    }

    /**
     * Builds a bridge over a custom source table (not used by autowiring).
     */
    public static function withSources(WikidataClient $client, SourceTable $sources): self
    {
        $bridge = new self($client);
        $bridge->sources = $sources;

        return $bridge;
    }

    /**
     * @param string[] $urls
     */
    public function resolveExternalId(array $urls): ?string
    {
        return WikidataIdResolver::resolve($urls, $this->sources);
    }

    /**
     * Null for everything that is "not found" in the sense of the bridge: an unknown key, no item
     * or several items for an IMDb/Kinopoisk id, an item with parts (a series: a season cannot be
     * guessed), an item without any anime-catalog link left after the selection. A network failure
     * or a broken answer is an exception.
     */
    public function findById(string $externalId): ?PluginAnimeData
    {
        if (preg_match('/^Q[1-9]\d*\z/', $externalId) === 1) {
            $itemId = $externalId;
        } elseif (preg_match('/^imdb:(tt\d+)\z/', $externalId, $match) === 1) {
            $itemId = $this->client->findItemByProperty(WikidataClient::PROPERTY_EXTERNAL_ID_IMDB, $match[1]);
        } elseif (preg_match('/^kinopoisk:([1-9]\d*)\z/', $externalId, $match) === 1) {
            $itemId = $this->client->findItemByProperty(WikidataClient::PROPERTY_EXTERNAL_ID_KINOPOISK, $match[1]);
        } else {
            return null;
        }
        if ($itemId === null) {
            return null;
        }

        $entity = $this->client->fetchEntity($itemId);
        if ($entity === null) {
            return null;
        }
        $claims = $entity['claims'] ?? null;
        if (\is_array($claims) && isset($claims[self::PROPERTY_HAS_PARTS])) {
            return null;
        }

        $links = EntityLinks::collect($this->sources, $entity);
        if (!$links['catalog']) {
            return null;
        }

        $entityId = \is_string($entity['id'] ?? null) && preg_match('/^Q[1-9][0-9]*\z/', $entity['id']) === 1 ? $entity['id'] : $itemId;
        $links['urls'][] = 'https://www.wikidata.org/wiki/'.$entityId;

        return new PluginAnimeData(title: self::title($entity, $entityId), externalUrls: $links['urls']);
    }

    /**
     * @return list<string>
     */
    public function getFillableFields(): array
    {
        return ['externalUrls'];
    }

    /**
     * Always empty: a search by title among the items with anime-catalog ids finds almost nothing,
     * and a record created from such a hit would be wrong. A record is linked by a link instead.
     *
     * @return list<SearchByPluginCandidate>
     */
    public function find(string $name, ?callable $onHeartbeat = null): array
    {
        return [];
    }

    /**
     * Only the DTO needs it (the host does not write it: it is not a fillable field): the label in
     * ja, en, ru, then the item id.
     *
     * @param array<string, mixed> $entity
     */
    private static function title(array $entity, string $entityId): string
    {
        foreach (['ja', 'en', 'ru'] as $language) {
            $label = $entity['labels'][$language]['value'] ?? null;
            if (\is_string($label) && $label !== '') {
                return $label;
            }
        }

        return $entityId;
    }
}
