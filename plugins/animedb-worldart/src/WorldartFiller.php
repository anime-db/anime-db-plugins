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

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\Plugins\AnimedbWorldart\ExternalId\WorldartIdResolver;
use AnimeDb\Plugins\AnimedbWorldart\Http\WorldartClient;
use AnimeDb\Plugins\AnimedbWorldart\Http\WorldartRequestException;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\CountryMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\CoverMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\DatesMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\DescriptionMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\ExternalUrlsMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\NamesMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\SynopsisMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\TaxonomyMapper;
use AnimeDb\Plugins\AnimedbWorldart\Mapping\TypeMapper;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use AnimeDb\Plugins\AnimedbWorldart\Search\QueryEncoder;
use AnimeDb\Plugins\AnimedbWorldart\Search\SearchPageParser;
use AnimeDb\Plugins\AnimedbWorldart\Search\SearchResult;
use AnimeDb\Plugins\AnimedbWorldart\Search\TitleNormalizer;

/**
 * Источник World-Art: единственная точка плагина для хоста — распознавание id по уже
 * прикреплённым к записи ссылкам, поиск тайтла по названию и заполнение записи по карточке.
 *
 * Всё собрано в одном классе, потому что второй filler-совместимый класс на тот же id плагина
 * дал бы коллизию тегов на хосте.
 *
 * Выдача сайта отсортирована по году по убыванию, а хост берёт первого кандидата без выбора, поэтому
 * кандидаты переупорядочиваются: сначала точные совпадения названия с запросом по году возрастания
 * (оригинал раньше продолжений), затем остальные в порядке сайта. Это эвристика, а не гарантия.
 */
final class WorldartFiller implements FillerInterface
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
     * The card is taken only through the client (card cache, limiter). Nothing is returned for an id
     * that is not a positive integer, for a title the site does not have and for a card whose title
     * did not parse: `PluginAnimeData::$title` is not nullable. `studios` and `images` are not filled.
     *
     * @throws WorldartRequestException the site is unreachable or answered with a failure
     */
    public function findById(string $externalId): ?PluginAnimeData
    {
        $id = preg_match('/^[1-9][0-9]*\z/', $externalId) === 1 ? filter_var($externalId, \FILTER_VALIDATE_INT) : false;
        if ($id === false) {
            return null;
        }

        $page = $this->client->fetchCard($id);
        if ($page === null) {
            return null;
        }

        $title = NamesMapper::title($page);
        if ($title === null) {
            return null;
        }

        $dates = DatesMapper::map($page);
        $premiere = $dates['premiere'];
        $end = $dates['end'];
        // a card with the end earlier than the premiere is a mistake of the card: the host rejects such a range
        if ($premiere !== null && $end !== null && $end < $premiere) {
            $end = null;
        }

        $taxonomy = TaxonomyMapper::genresAndThemes($page);

        return new PluginAnimeData(
            title: $title,
            alternativeNames: NamesMapper::alternativeNames($page),
            descriptions: DescriptionMapper::map($page) ?? $this->synopsisDescription($page, $id),
            genres: $taxonomy['genres'] === [] ? null : $taxonomy['genres'],
            themes: $taxonomy['themes'] === [] ? null : $taxonomy['themes'],
            demographic: TaxonomyMapper::demographic($page),
            type: TypeMapper::type($page),
            datePremiere: $premiere,
            dateEnd: $end,
            durationMinutes: TypeMapper::durationMinutes($page),
            episodesCount: TypeMapper::episodesCount($page),
            countries: CountryMapper::map($page),
            cover: CoverMapper::map($page, $id),
            externalUrls: ExternalUrlsMapper::map($page, $id),
        );
    }

    /**
     * The description from the unmoderated synopsis page; requested only when the card shows the
     * service notice instead of a description. The field is optional, so any failure gives null
     * and does not fail the whole entry.
     *
     * @return array<string, string>|null
     */
    private function synopsisDescription(WorldartPage $card, int $id): ?array
    {
        if (!DescriptionMapper::hasOnlyServiceNotice($card)) {
            return null;
        }

        try {
            $page = $this->client->fetchSynopsis($id);
        } catch (WorldartRequestException) {
            return null;
        }

        return $page === null ? null : SynopsisMapper::map($page);
    }

    /**
     * @return list<string>
     */
    public function getFillableFields(): array
    {
        return [
            'title',
            'alternativeNames',
            'descriptions',
            'genres',
            'themes',
            'demographic',
            'type',
            'datePremiere',
            'dateEnd',
            'durationMinutes',
            'episodesCount',
            'countries',
            'cover',
            'externalUrls',
        ];
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

        // the card is taken through the card cache: it is most likely needed right after
        $title = $this->client->fetchCard((int) $id, $onHeartbeat)?->title();
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
