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

namespace AnimeDb\Plugins\AnimedbShikimori\Widget;

use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetMetadata;
use AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlClient;
use Twig\Environment;

/**
 * Entry widget listing anime related to the current catalog record (sequels, prequels,
 * adaptations, side stories, ...), sourced from Shikimori's GraphQL `Anime.related` — anonymous,
 * no Bearer needed.
 *
 * `related` mixes two kinds of relations, `anime` and `manga`: only the former is a catalog
 * record this app can link to, so manga entries are dropped rather than shown as dead links.
 * Shown newest name has no natural order from the API itself, so results are sorted by release
 * date ({@see self::sortByAiredOn()}); relations with no known date sort last rather than being
 * dropped, since they are still relevant, just unordered relative to each other.
 *
 * All matches are rendered — Shikimori's related list for a single title is small (rarely more
 * than a handful of entries), so no limit/pagination is needed; the CSS-only horizontal-scroll
 * "carousel" in the template keeps a long list from pushing the rest of the page down.
 */
final class RelatedWidget implements EntryWidgetInterface
{
    private const TEMPLATE = '@AnimedbShikimori/widget/related.html.twig';
    private const DEFAULT_ENDPOINT = 'https://shikimori.io';

    private const QUERY = <<<'GRAPHQL'
        query($ids: String) {
            animes(ids: $ids, limit: 1) {
                related {
                    anime {
                        id
                        name
                        russian
                        airedOn { date }
                        poster { originalUrl }
                    }
                }
            }
        }
        GRAPHQL;

    public function __construct(
        private readonly GraphQlClient $client,
        private readonly CatalogReaderInterface $catalogReader,
        private readonly Environment $twig,
    ) {
    }

    public static function metadata(): WidgetMetadata
    {
        return new WidgetMetadata(
            'related',
            'widget.related.title',
            'widget.related.description',
        );
    }

    public function render(AnimeId $anime): string
    {
        $externalId = $this->catalogReader->read($anime)?->externalId;

        return $this->twig->render(self::TEMPLATE, [
            'items' => $externalId === null ? [] : $this->fetchItems($externalId),
        ]);
    }

    /**
     * @return list<array{thumbnail: ?string, title: string, subtitle: ?string, url: string}>
     */
    private function fetchItems(string $externalId): array
    {
        $data = $this->client->query(self::QUERY, ['ids' => $externalId]);
        $animes = \is_array($data['animes'] ?? null) ? $data['animes'] : [];
        $related = \is_array($animes[0]['related'] ?? null) ? $animes[0]['related'] : [];

        $entries = [];
        foreach ($related as $relation) {
            $anime = \is_array($relation) ? ($relation['anime'] ?? null) : null;
            $entry = self::buildEntry($anime);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        usort($entries, static fn (array $a, array $b): int => self::compareByAiredOn($a['sortDate'], $b['sortDate']));

        return array_map(static fn (array $entry): array => [
            'thumbnail' => $entry['thumbnail'],
            'title' => $entry['title'],
            'subtitle' => $entry['sortDate'],
            'url' => $entry['url'],
        ], $entries);
    }

    /**
     * @param mixed $anime a single `Anime.related[].anime` element, or null for a manga relation
     *
     * @return array{thumbnail: ?string, title: string, url: string, sortDate: ?string}|null
     */
    private static function buildEntry(mixed $anime): ?array
    {
        if (!\is_array($anime) || !isset($anime['id'])) {
            return null;
        }

        $title = $anime['name'] ?? $anime['russian'] ?? null;
        if (!\is_string($title) || $title === '') {
            return null;
        }

        $id = (string) $anime['id'];

        return [
            'thumbnail' => \is_string($anime['poster']['originalUrl'] ?? null) ? $anime['poster']['originalUrl'] : null,
            'title' => $title,
            'url' => self::DEFAULT_ENDPOINT.'/animes/'.$id,
            'sortDate' => \is_string($anime['airedOn']['date'] ?? null) ? $anime['airedOn']['date'] : null,
        ];
    }

    private static function compareByAiredOn(?string $a, ?string $b): int
    {
        return match (true) {
            $a === $b => 0,
            $a === null => 1,
            $b === null => -1,
            default => $a <=> $b,
        };
    }
}
