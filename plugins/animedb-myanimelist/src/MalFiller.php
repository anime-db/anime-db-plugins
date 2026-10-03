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

namespace AnimeDb\Plugins\AnimedbMyanimelist;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use AnimeDb\Plugins\AnimedbMyanimelist\ExternalId\MalIdResolver;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient;

/**
 * Источник MyAnimeList: поиск аниме по названию и распознавание id по уже прикреплённым к
 * записи ссылкам, через анонимные чтения MyAnimeList API v2 (`find()`/`resolveExternalId()`).
 *
 * Единственный класс плагина, реализующий {@see SearchByPluginInterface}: следующие задачи
 * серии расширяют именно его до `FillerInterface` (заполнение карточки) и `SyncInterface`
 * (синхронизация watch-листа), а не добавляют второй filler-совместимый класс на тот же id
 * плагина — это дало бы коллизию тегов `app.filler`/`app.sync` на хосте.
 *
 * Тяжёлый HTTP вынесен в {@see MalApiClient}.
 */
final class MalFiller implements SearchByPluginInterface
{
    /**
     * Minimum length of a search query `q`, in characters (not bytes — confirmed live against
     * a 64-character multibyte Japanese query, which MyAnimeList accepts, while the same
     * length in bytes would be well over its limit). MyAnimeList itself rejects a shorter `q`
     * with HTTP 400 (`{"message":"invalid q","error":"bad_request"}`); this plugin never sends
     * such a request at all, returning an empty result instead — the host's bulk filler would
     * otherwise turn that 400 into an exception on an ordinary short title.
     */
    public const MIN_QUERY_LENGTH = 3;

    /**
     * Maximum length of a search query `q`, in characters. A longer query is truncated with
     * {@see mb_substr()} rather than rejected — confirmed live: MyAnimeList itself rejects a
     * 65-character query (and accepts 64) with the same HTTP 400 as a too-short one.
     */
    public const MAX_QUERY_LENGTH = 64;

    private const SEARCH_LIMIT = 10;
    private const SEARCH_FIELDS = 'id,title';

    public function __construct(
        private readonly MalApiClient $client,
        private readonly OwnManifestInterface $ownManifest,
    ) {
    }

    /**
     * Распознаёт id аниме на MyAnimeList по уже прикреплённым к записи ссылкам.
     *
     * @param string[] $urls
     */
    public function resolveExternalId(array $urls): ?string
    {
        return MalIdResolver::resolve($urls);
    }

    /**
     * @param callable(): void|null $onHeartbeat
     *
     * @return list<SearchByPluginCandidate>
     */
    public function find(string $name, ?callable $onHeartbeat = null): array
    {
        if (mb_strlen($name) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        if (mb_strlen($name) > self::MAX_QUERY_LENGTH) {
            $name = mb_substr($name, 0, self::MAX_QUERY_LENGTH);
        }

        $data = $this->client->get('/anime', [
            'q' => $name,
            'limit' => self::SEARCH_LIMIT,
            'fields' => self::SEARCH_FIELDS,
        ], $onHeartbeat);

        $items = \is_array($data['data'] ?? null) ? $data['data'] : [];

        $candidates = [];
        foreach ($items as $item) {
            $node = \is_array($item) ? ($item['node'] ?? null) : null;
            if (!\is_array($node)) {
                continue;
            }

            $title = $node['title'] ?? null;
            if (!\is_string($title) || $title === '') {
                continue;
            }

            $id = $node['id'] ?? null;
            if (!\is_int($id) || $id <= 0) {
                continue;
            }

            $candidates[] = new SearchByPluginCandidate($this->ownManifest->id(), $title, (string) $id);
        }

        return $candidates;
    }
}
