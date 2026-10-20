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

use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use AnimeDb\Plugins\AnimedbWikidata\ExternalId\WikidataIdResolver;

/**
 * The linking bridge through Wikidata: recognizes the links of a record that has no anime-catalog
 * links (see {@see WikidataIdResolver}). It does not fill anything yet and is not a source of
 * metadata, nor a Kinopoisk or IMDb plugin.
 *
 * The plugin id of a record is a search key: `Q…`, `imdb:tt…` or `kinopoisk:N`. It does not change
 * after the first linking, and the later `findById()` accepts all three forms.
 */
final class WikidataBridge implements SearchByPluginInterface
{
    /**
     * @param string[] $urls
     */
    public function resolveExternalId(array $urls): ?string
    {
        return WikidataIdResolver::resolve($urls);
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
}
