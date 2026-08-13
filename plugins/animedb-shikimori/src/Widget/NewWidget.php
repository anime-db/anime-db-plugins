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

use AnimeDb\PluginContracts\Widget\CatalogWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetMetadata;
use AnimeDb\Plugins\AnimedbShikimori\ExternalId\ShikimoriIdResolver;
use AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlClient;
use AnimeDb\Plugins\AnimedbShikimori\Http\UnauthorizedHttpException;
use AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthClient;
use Twig\Environment;

/**
 * Catalog widget listing newly aired anime from Shikimori, OAuth-aware: when the user is
 * connected, the query passes `mylist` so Shikimori itself excludes everything already on their
 * list (server-side filtering — no over-fetch, no client-side diffing against the user's own
 * watch list).
 *
 * `mylist`'s exact syntax ("list of values separated by comma, `!` negates") was confirmed via a
 * live, unauthenticated GraphQL introspection query against `shikimori.io`'s `MylistString`
 * scalar description on 2026-08-11 (the argument itself carries no description in the schema);
 * {@see self::MYLIST_EXCLUDE_ALL} negates every known status so "already on the list" excludes
 * every status, not just a subset.
 *
 * The access token is read directly from {@see ShikimoriOAuthClient::accessToken()}, never
 * refreshed here: unlike {@see \AnimeDb\Plugins\AnimedbShikimori\Sync\ShikimoriAuthRetrier}
 * (used by push/pull), a 401 on this widget's authed query is treated as "fall back to the
 * anonymous query", not "attempt a refresh" — refresh-on-401 is deliberately reserved for the
 * sync path, so a slow/failing token refresh never blocks a page render.
 */
final class NewWidget implements CatalogWidgetInterface
{
    private const TEMPLATE = '@AnimedbShikimori/widget/new.html.twig';
    private const DEFAULT_ENDPOINT = 'https://shikimori.io';
    private const LIMIT = 20;
    private const MYLIST_EXCLUDE_ALL = '!planned,!watching,!rewatching,!completed,!on_hold,!dropped';

    private const QUERY = <<<'GRAPHQL'
        query($limit: PositiveInt, $mylist: MylistString) {
            animes(order: aired_on, limit: $limit, mylist: $mylist) {
                id
                name
                russian
                airedOn { date }
                poster { originalUrl }
            }
        }
        GRAPHQL;

    public function __construct(
        private readonly GraphQlClient $client,
        private readonly ShikimoriOAuthClient $oauth,
        private readonly Environment $twig,
    ) {
    }

    public static function metadata(): WidgetMetadata
    {
        return new WidgetMetadata(
            'new',
            'Новинки Shikimori',
            'Показывает недавно вышедшие аниме; при подключённом аккаунте Shikimori скрывает тайтлы, уже добавленные в список.',
        );
    }

    /**
     * @param string[] $urls
     */
    public function resolveExternalId(array $urls): ?string
    {
        return ShikimoriIdResolver::resolve($urls);
    }

    public function render(): string
    {
        $bearer = $this->oauth->accessToken();

        if ($bearer !== null) {
            try {
                $items = $this->fetchItems(['limit' => self::LIMIT, 'mylist' => self::MYLIST_EXCLUDE_ALL], $bearer);

                return $this->twig->render(self::TEMPLATE, ['items' => $items]);
            } catch (UnauthorizedHttpException) {
                // Token present but rejected (401/expired): fall back to the anonymous query
                // below, do not refresh — see class doc.
            }
        }

        return $this->twig->render(self::TEMPLATE, ['items' => $this->fetchItems(['limit' => self::LIMIT], null)]);
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return list<array{thumbnail: ?string, title: string, subtitle: ?string, url: string}>
     */
    private function fetchItems(array $variables, ?string $bearer): array
    {
        $data = $this->client->query(self::QUERY, $variables, null, $bearer);
        $animes = \is_array($data['animes'] ?? null) ? $data['animes'] : [];

        $items = [];
        foreach ($animes as $anime) {
            $item = self::buildItem($anime);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param mixed $anime a single `Query.animes[]` element
     *
     * @return array{thumbnail: ?string, title: string, subtitle: ?string, url: string}|null
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

        return [
            'thumbnail' => \is_string($anime['poster']['originalUrl'] ?? null) ? $anime['poster']['originalUrl'] : null,
            'title' => $title,
            'subtitle' => \is_string($anime['airedOn']['date'] ?? null) ? $anime['airedOn']['date'] : null,
            'url' => self::DEFAULT_ENDPOINT.'/animes/'.$anime['id'],
        ];
    }
}
