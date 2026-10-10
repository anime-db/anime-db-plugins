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

namespace AnimeDb\Plugins\AnimedbAnidb\Widget;

use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetListItem;
use AnimeDb\PluginContracts\Widget\WidgetMetadata;
use AnimeDb\Plugins\AnimedbAnidb\Http\AniDbApiClient;
use AnimeDb\Plugins\AnimedbAnidb\Http\NotFoundHttpException;
use Twig\Environment;

/**
 * Entry widget listing the titles AniDB marks as similar to the current catalog record, read
 * from `/anime/similaranime/anime` of the card.
 *
 * Adapted from the MyAnimeList plugin widget of the same name. The card comes only through
 * {@see AniDbApiClient} (24-hour card cache, shared request limiter and ban guard), once per
 * render; no cover is shown and no request is made per title. Items keep the order of the API
 * response. User recommendations (`<recommendations>`) are not used.
 */
final class SimilarWidget implements EntryWidgetInterface
{
    private const TEMPLATE = '@AnimedbAnidb/widget/similar.html.twig';
    private const ENDPOINT = 'https://anidb.net';

    public function __construct(
        private readonly AniDbApiClient $client,
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
        // same bound as AnidbFiller::findById(): a bare positive integer that fits an AniDB aid
        if (preg_match('/^[1-9]\d{0,8}$/', $externalId) !== 1) {
            return [];
        }

        try {
            $card = $this->client->fetchAnime((int) $externalId);
        } catch (NotFoundHttpException) {
            return [];
        }

        $nodes = $card->xpath('/anime/similaranime/anime');
        if ($nodes === false || $nodes === null) {
            return [];
        }

        $items = [];
        foreach ($nodes as $node) {
            $item = self::buildItem($node);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private static function buildItem(\SimpleXMLElement $node): ?WidgetListItem
    {
        $id = (string) $node['id'];
        $title = trim((string) $node);
        if (preg_match('/^[1-9]\d{0,8}$/', $id) !== 1 || $title === '') {
            return null;
        }

        return new WidgetListItem(
            null,
            $title,
            null,
            self::ENDPOINT.'/anime/'.$id,
        );
    }
}
