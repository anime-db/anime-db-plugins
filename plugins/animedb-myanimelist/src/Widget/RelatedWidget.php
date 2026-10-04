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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Widget;

use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetListItem;
use AnimeDb\PluginContracts\Widget\WidgetMetadata;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\NotFoundHttpException;
use Twig\Environment;

/**
 * Entry widget listing anime related to the current catalog record (sequels, prequels,
 * adaptations, side stories, ...), sourced from MyAnimeList's card field `related_anime`
 * ({@see MalApiClient}) — anonymous, no Bearer needed.
 *
 * Adapted from {@see \AnimeDb\Plugins\AnimedbShikimori\Widget\RelatedWidget}, but deliberately
 * NOT a copy of its sorting behaviour: `related_anime` carries no air date, so there is nothing
 * to sort by without a further request per relation, and at this plugin's ~1 request/second rate
 * limit that would turn a single card render into one second of latency per relation. Items are
 * rendered in the order the API returns them instead.
 *
 * All matches are rendered — a title's related list is small (rarely more than a handful of
 * entries), so no limit/pagination is needed; the host's `plugin/_widget_list` helper renders it
 * as a horizontally scrolling list, which keeps a long list from pushing the rest of the page
 * down.
 */
final class RelatedWidget implements EntryWidgetInterface
{
    private const TEMPLATE = '@AnimedbMyanimelist/widget/related.html.twig';
    private const DEFAULT_ENDPOINT = 'https://myanimelist.net';
    private const CARD_FIELDS = 'related_anime';

    public function __construct(
        private readonly MalApiClient $client,
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
     * @return list<WidgetListItem>
     */
    private function fetchItems(string $externalId): array
    {
        // $externalId is interpolated into the request path below, same as
        // MalFiller::findById(); restricted to a bare positive integer for the same reason.
        if (preg_match('/^[1-9]\d*$/', $externalId) !== 1) {
            return [];
        }

        try {
            $data = $this->client->get('/anime/'.$externalId, ['fields' => self::CARD_FIELDS]);
        } catch (NotFoundHttpException) {
            return [];
        }
        $related = \is_array($data['related_anime'] ?? null) ? $data['related_anime'] : [];

        $items = [];
        foreach ($related as $relation) {
            $item = self::buildItem($relation);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param mixed $relation a single `related_anime[]` element
     */
    private static function buildItem(mixed $relation): ?WidgetListItem
    {
        $node = \is_array($relation) ? ($relation['node'] ?? null) : null;
        if (!\is_array($node) || !\is_int($node['id'] ?? null) || $node['id'] < 1) {
            return null;
        }

        $title = $node['title'] ?? null;
        if (!\is_string($title) || $title === '') {
            return null;
        }

        $thumbnail = $node['main_picture']['large'] ?? null;
        $subtitle = \is_array($relation) ? ($relation['relation_type_formatted'] ?? null) : null;

        return new WidgetListItem(
            \is_string($thumbnail) && $thumbnail !== '' ? $thumbnail : null,
            $title,
            \is_string($subtitle) && $subtitle !== '' ? $subtitle : null,
            self::DEFAULT_ENDPOINT.'/anime/'.$node['id'],
        );
    }
}
