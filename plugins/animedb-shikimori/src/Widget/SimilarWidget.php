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
use AnimeDb\Plugins\AnimedbShikimori\ExternalId\ShikimoriIdResolver;
use AnimeDb\Plugins\AnimedbShikimori\Http\ShikimoriRestClient;
use Twig\Environment;

/**
 * Entry widget listing anime similar to the current catalog record, sourced from Shikimori's
 * REST `GET /api/animes/:id/similar` — public, no Bearer needed. This data has no GraphQL
 * equivalent ({@see \AnimeDb\Plugins\AnimedbShikimori\Widget\RelatedWidget} uses GraphQL because
 * `related` does have one), hence going through {@see ShikimoriRestClient} instead.
 *
 * Only `id`/`name`/`russian`/`image` are read from the REST response — the endpoint also returns
 * score/kind/status/dates, but the widget has no use for them and mapping them would be scope the
 * task did not ask for.
 *
 * All matches are rendered, same as {@see RelatedWidget} — the CSS-only horizontal-scroll
 * "carousel" in the template keeps a long list from pushing the rest of the page down.
 */
final class SimilarWidget implements EntryWidgetInterface
{
    private const TEMPLATE = '@AnimedbShikimori/widget/similar.html.twig';
    private const DEFAULT_ENDPOINT = 'https://shikimori.io';

    public function __construct(
        private readonly ShikimoriRestClient $restClient,
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

    /**
     * @param string[] $urls
     */
    public function resolveExternalId(array $urls): ?string
    {
        return ShikimoriIdResolver::resolve($urls);
    }

    public function render(AnimeId $anime): string
    {
        $externalId = $this->catalogReader->read($anime)?->externalId;

        return $this->twig->render(self::TEMPLATE, [
            'items' => $externalId === null ? [] : $this->fetchItems($externalId),
        ]);
    }

    /**
     * @return list<array{thumbnail: ?string, title: string, subtitle: null, url: string}>
     */
    private function fetchItems(string $externalId): array
    {
        $items = [];
        foreach ($this->restClient->getSimilarAnimes($externalId) as $anime) {
            $item = self::buildItem($anime);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param mixed $anime a single element of `GET /api/animes/:id/similar`'s response
     *
     * @return array{thumbnail: ?string, title: string, subtitle: null, url: string}|null
     */
    private static function buildItem(mixed $anime): ?array
    {
        if (!\is_array($anime) || !isset($anime['id'])) {
            return null;
        }

        $title = $anime['name'] ?? $anime['russian'] ?? null;
        if (!\is_string($title) || $title === '') {
            return null;
        }

        $thumbnailPath = $anime['image']['original'] ?? null;

        return [
            'thumbnail' => \is_string($thumbnailPath) && $thumbnailPath !== '' ? self::DEFAULT_ENDPOINT.$thumbnailPath : null,
            'title' => $title,
            'subtitle' => null,
            'url' => self::DEFAULT_ENDPOINT.'/animes/'.$anime['id'],
        ];
    }
}
