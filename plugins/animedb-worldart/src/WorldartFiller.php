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

namespace AnimeDb\Plugins\AnimedbWorldart;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use AnimeDb\Plugins\AnimedbWorldart\ExternalId\WorldartIdResolver;
use AnimeDb\Plugins\AnimedbWorldart\Http\WorldartClient;
use AnimeDb\Plugins\AnimedbWorldart\Http\WorldartRequestException;
use AnimeDb\Plugins\AnimedbWorldart\Search\QueryEncoder;
use AnimeDb\Plugins\AnimedbWorldart\Search\SearchPageParser;
use AnimeDb\Plugins\AnimedbWorldart\Search\SearchResult;
use AnimeDb\Plugins\AnimedbWorldart\Search\TitleNormalizer;

/**
 * Источник World-Art: единственная точка плагина для хоста — распознавание id по уже
 * прикреплённым к записи ссылкам и поиск тайтла по названию.
 *
 * Всё собрано в одном классе, потому что второй filler-совместимый класс на тот же id плагина
 * дал бы коллизию тегов на хосте.
 *
 * Выдача сайта отсортирована по году по убыванию, а хост берёт первого кандидата без выбора, поэтому
 * кандидаты переупорядочиваются: сначала точные совпадения названия с запросом по году возрастания
 * (оригинал раньше продолжений), затем остальные в порядке сайта. Это эвристика, а не гарантия.
 */
final class WorldartFiller implements SearchByPluginInterface
{
    public function __construct(
        private readonly WorldartClient $client,
        private readonly OwnManifestInterface $ownManifest,
    ) {
    }

    /**
     * @param string[] $urls
     */
    public function resolveExternalId(array $urls): ?string
    {
        return WorldartIdResolver::resolve($urls);
    }

    /**
     * @param callable(): void|null $onHeartbeat
     *
     * @return list<SearchByPluginCandidate>
     *
     * @throws WorldartRequestException the site is unreachable or answered with a failure
     */
    public function find(string $name, ?callable $onHeartbeat = null): array
    {
        $encoded = QueryEncoder::encode($name);
        if ($encoded === null) {
            return [];
        }

        $page = $this->client->search($encoded, $onHeartbeat);

        $target = $page->refreshTarget();
        if ($target !== null) {
            return $this->singleResult($target, $onHeartbeat);
        }

        $candidates = [];
        foreach ($this->order(SearchPageParser::parse($page), $name) as $result) {
            $candidates[] = new SearchByPluginCandidate($this->ownManifest->id(), $result->name, $result->id);
        }

        return $candidates;
    }

    /**
     * @param callable(): void|null $onHeartbeat
     *
     * @return list<SearchByPluginCandidate>
     */
    private function singleResult(string $target, ?callable $onHeartbeat): array
    {
        // the site writes targets relative, with or without the leading slash; "//host" is not ours
        $url = parse_url($target, \PHP_URL_SCHEME) === null && !str_starts_with($target, '//')
            ? WorldartClient::BASE_URL.'/'.ltrim($target, '/')
            : $target;
        $id = WorldartIdResolver::resolve([$url]);
        if ($id === null) {
            throw new WorldartRequestException('World-Art redirected the search to an unexpected page.');
        }

        $title = $this->client->fetchCard((int) $id, $onHeartbeat)->title();
        if ($title === null) {
            return [];
        }

        return [new SearchByPluginCandidate($this->ownManifest->id(), $title, $id)];
    }

    /**
     * @param list<SearchResult> $results in the order of the site
     *
     * @return list<SearchResult>
     */
    private function order(array $results, string $query): array
    {
        $needle = TitleNormalizer::normalize($query);
        $exact = [];
        $rest = [];
        foreach ($results as $result) {
            if ($needle !== '' && $this->matches($result, $needle)) {
                $exact[] = $result;
            } else {
                $rest[] = $result;
            }
        }
        // the sort is stable: equal years keep the order of the site
        usort($exact, static fn (SearchResult $a, SearchResult $b): int => ($a->year ?? \PHP_INT_MAX) <=> ($b->year ?? \PHP_INT_MAX));

        return [...$exact, ...$rest];
    }

    private function matches(SearchResult $result, string $needle): bool
    {
        foreach ([$result->name, ...$result->otherNames] as $title) {
            if (TitleNormalizer::normalize($title) === $needle) {
                return true;
            }
        }

        return false;
    }
}
