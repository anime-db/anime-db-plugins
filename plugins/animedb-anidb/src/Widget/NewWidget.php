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

use AnimeDb\PluginContracts\Widget\CatalogWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetListItem;
use AnimeDb\PluginContracts\Widget\WidgetMetadata;
use AnimeDb\Plugins\AnimedbAnidb\Http\AniDbApiClient;
use Twig\Environment;

/**
 * Catalog widget listing the titles AniDB reports as popular right now, read from
 * `/hotanime/anime` of `request=hotanime`. It is not a strict list of the current season.
 *
 * The list comes only through {@see AniDbApiClient} (24-hour disk cache, shared request limiter
 * and ban guard), once per render; everything shown is taken from that response, so there is no
 * request per title. Items keep the response order, titles marked `restricted="true"` are left
 * out, and at most {@see self::LIMIT} items are shown.
 */
final class NewWidget implements CatalogWidgetInterface
{
    public const LIMIT = 20;

    private const TEMPLATE = '@AnimedbAnidb/widget/new.html.twig';
    private const ENDPOINT = 'https://anidb.net';
    private const COVER_URL = 'https://cdn-eu.anidb.net/images/main/';

    public function __construct(
        private readonly AniDbApiClient $client,
        private readonly Environment $twig,
    ) {
    }

    public static function metadata(): WidgetMetadata
    {
        return new WidgetMetadata(
            'new',
            'widget.new.title',
            'widget.new.description',
        );
    }

    public function render(): string
    {
        return $this->twig->render(self::TEMPLATE, ['items' => $this->fetchItems()]);
    }

    /**
     * @return list<WidgetListItem>
     */
    private function fetchItems(): array
    {
        $nodes = $this->client->fetchHotAnime()->xpath('/hotanime/anime');
        if ($nodes === false || $nodes === null) {
            return [];
        }

        $items = [];
        foreach ($nodes as $node) {
            $item = self::buildItem($node);
            if ($item === null) {
                continue;
            }
            $items[] = $item;
            if (\count($items) >= self::LIMIT) {
                break;
            }
        }

        return $items;
    }

    private static function buildItem(\SimpleXMLElement $node): ?WidgetListItem
    {
        if (strtolower(trim((string) $node['restricted'])) === 'true') {
            return null;
        }

        $id = (string) $node['id'];
        $titles = $node->xpath('title[@type="main"]');
        $title = $titles === false || $titles === null || $titles === [] ? '' : trim((string) $titles[0]);
        if (preg_match('/^[1-9]\d{0,8}$/', $id) !== 1 || $title === '') {
            return null;
        }

        $thumbnail = null;
        $picture = trim((string) $node->picture);
        if ($picture !== '') {
            if (preg_match('/^[\w.-]+$/', $picture) !== 1) {
                return null;
            }
            $thumbnail = self::COVER_URL.$picture;
        }

        return new WidgetListItem($thumbnail, $title, null, self::ENDPOINT.'/anime/'.$id);
    }
}
