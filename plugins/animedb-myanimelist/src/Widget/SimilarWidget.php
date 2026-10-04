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
 * Entry widget listing anime MyAnimeList's own community recommends alongside the current
 * catalog record, sourced from the card field `recommendations` ({@see MalApiClient}) —
 * anonymous, no Bearer needed.
 *
 * Adapted from {@see \AnimeDb\Plugins\AnimedbShikimori\Widget\SimilarWidget}, but unlike that
 * widget (a dedicated REST endpoint, `GET /api/animes/:id/similar`), MyAnimeList has no separate
 * "similar" endpoint: `recommendations` is part of the one card response this widget already
 * requests, so no second endpoint or extra request is introduced.
 *
 * All matches are rendered, same as {@see RelatedWidget} — the host's `plugin/_widget_list`
 * helper renders it as a horizontally scrolling list, which keeps a long list from pushing the
 * rest of the page down.
 */
final class SimilarWidget implements EntryWidgetInterface
{
    private const TEMPLATE = '@AnimedbMyanimelist/widget/similar.html.twig';
    private const DEFAULT_ENDPOINT = 'https://myanimelist.net';
    private const CARD_FIELDS = 'recommendations';

    public function __construct(
        private readonly MalApiClient $client,
        private readonly CatalogReaderInterface $catalogReader,
        private readonly Environment $twig,
    ) {
    }

    public static function metadata(): WidgetMetadata
    {
        return new WidgetMetadata(
            'similar',
            'widget.similar.title',
            'widget.similar.description',
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
        $recommendations = \is_array($data['recommendations'] ?? null) ? $data['recommendations'] : [];

        $items = [];
        foreach ($recommendations as $recommendation) {
            $item = self::buildItem($recommendation);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param mixed $recommendation a single `recommendations[]` element
     */
    private static function buildItem(mixed $recommendation): ?WidgetListItem
    {
        $node = \is_array($recommendation) ? ($recommendation['node'] ?? null) : null;
        if (!\is_array($node) || !\is_int($node['id'] ?? null) || $node['id'] < 1) {
            return null;
        }

        $title = $node['title'] ?? null;
        if (!\is_string($title) || $title === '') {
            return null;
        }

        $thumbnail = $node['main_picture']['large'] ?? null;

        return new WidgetListItem(
            \is_string($thumbnail) && $thumbnail !== '' ? $thumbnail : null,
            $title,
            null,
            self::DEFAULT_ENDPOINT.'/anime/'.$node['id'],
        );
    }
}
