<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace AnimeDb\Plugins\AnimedbShikimori;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlClient;
use AnimeDb\Plugins\AnimedbShikimori\Mapping\AnimeTypeMapper;
use AnimeDb\Plugins\AnimedbShikimori\Mapping\DescriptionCleaner;
use AnimeDb\Plugins\AnimedbShikimori\Mapping\GenreMapper;

/**
 * Источник Shikimori — поиск и заполнение карточек аниме через анонимные чтения GraphQL API
 * Shikimori (без OAuth: `find()`/`findById()` не читают и не пишут пользовательские данные).
 *
 * Фаза 1 плагина: {@see self::resolveExternalId()} и поиск/заполнение полностью
 * реализованы. Вне рамок этой фазы — OAuth, страница настроек (endpoint читается из
 * {@see \AnimeDb\PluginContracts\Settings\SettingsStoreInterface} уже сейчас, но форму для его
 * редактирования даёт следующая фаза), sync, виджеты.
 *
 * Маппинг словарей жанров/тем/демографии не полагается на `genres[].kind` Shikimori — см. класс
 * {@see GenreMapper}.
 */
final class ShikimoriFiller implements FillerInterface
{
    private const SEARCH_LIMIT = 15;

    private const SEARCH_QUERY = <<<'GRAPHQL'
        query($search: String, $limit: Int) {
            animes(search: $search, limit: $limit) {
                id
                name
                russian
                kind
            }
        }
        GRAPHQL;

    private const CARD_QUERY = <<<'GRAPHQL'
        query($ids: String, $limit: Int) {
            animes(ids: $ids, limit: $limit) {
                id
                name
                russian
                english
                japanese
                synonyms
                kind
                rating
                status
                episodes
                duration
                score
                airedOn { date }
                releasedOn { date }
                poster { originalUrl mainUrl }
                studios { id name }
                genres { id name russian kind }
                description
            }
        }
        GRAPHQL;

    public function __construct(
        private readonly GraphQlClient $client,
        private readonly OwnManifestInterface $ownManifest,
    ) {
    }

    /**
     * Распознаёт id аниме на Shikimori по уже прикреплённым к записи ссылкам.
     *
     * Матчит домены Shikimori (`shikimori.io`/`.one`/`.org`, в т.ч. поддомены) и
     * путь вида `/animes/<id>` или `/animes/z<id>-<slug>`, возвращает числовой id.
     *
     * @param string[] $urls
     */
    public function resolveExternalId(array $urls): ?string
    {
        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }

            $host = parse_url($url, \PHP_URL_HOST);
            if (!is_string($host) || preg_match('/(^|\.)shikimori\.(io|one|org)$/i', $host) !== 1) {
                continue;
            }

            $path = parse_url($url, \PHP_URL_PATH);
            if (is_string($path) && preg_match('#/animes/z?(\d+)#', $path, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @param callable(): void|null $onHeartbeat
     *
     * @return list<SearchByPluginCandidate>
     */
    public function find(string $name, ?callable $onHeartbeat = null): array
    {
        $data = $this->client->query(self::SEARCH_QUERY, ['search' => $name, 'limit' => self::SEARCH_LIMIT], $onHeartbeat);
        $animes = \is_array($data['animes'] ?? null) ? $data['animes'] : [];

        $candidates = [];
        foreach ($animes as $anime) {
            if (!\is_array($anime) || !isset($anime['id'])) {
                continue;
            }

            $displayName = $anime['name'] ?? $anime['russian'] ?? null;
            if (!\is_string($displayName) || $displayName === '') {
                continue;
            }

            $candidates[] = new SearchByPluginCandidate($this->ownManifest->id(), $displayName, (string) $anime['id']);
        }

        return $candidates;
    }

    public function findById(string $externalId): ?PluginAnimeData
    {
        $data = $this->client->query(self::CARD_QUERY, ['ids' => $externalId, 'limit' => 1]);
        $animes = \is_array($data['animes'] ?? null) ? $data['animes'] : [];
        $anime = $animes[0] ?? null;

        if (!\is_array($anime) || !\is_string($anime['name'] ?? null) || $anime['name'] === '') {
            return null;
        }

        $title = $anime['name'];
        $genres = \is_array($anime['genres'] ?? null) ? $anime['genres'] : [];
        $mappedGenres = GenreMapper::map($genres);

        return new PluginAnimeData(
            title: $title,
            alternativeNames: self::buildAlternativeNames($title, $anime),
            descriptions: self::buildDescriptions($anime),
            genres: $mappedGenres['genres'] === [] ? null : $mappedGenres['genres'],
            themes: $mappedGenres['themes'] === [] ? null : $mappedGenres['themes'],
            demographic: $mappedGenres['demographics'][0] ?? null,
            studios: self::buildStudios($anime),
            type: AnimeTypeMapper::map(\is_string($anime['kind'] ?? null) ? $anime['kind'] : null),
            datePremiere: self::parseDate($anime['airedOn']['date'] ?? null),
            dateEnd: self::parseDate($anime['releasedOn']['date'] ?? null),
            durationMinutes: \is_int($anime['duration'] ?? null) ? $anime['duration'] : null,
            episodesCount: \is_int($anime['episodes'] ?? null) && $anime['episodes'] > 0 ? $anime['episodes'] : null,
            cover: \is_string($anime['poster']['originalUrl'] ?? null) ? $anime['poster']['originalUrl'] : null,
        );
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
            'studios',
            'type',
            'datePremiere',
            'dateEnd',
            'durationMinutes',
            'episodesCount',
            'cover',
        ];
    }

    /**
     * @param array<string, mixed> $anime
     *
     * @return list<string>|null
     */
    private static function buildAlternativeNames(string $title, array $anime): ?array
    {
        $synonyms = \is_array($anime['synonyms'] ?? null) ? $anime['synonyms'] : [];
        $candidates = [$anime['russian'] ?? null, $anime['english'] ?? null, $anime['japanese'] ?? null, ...$synonyms];

        $names = [];
        foreach ($candidates as $candidate) {
            if (!\is_string($candidate) || $candidate === '' || $candidate === $title) {
                continue;
            }

            if (!\in_array($candidate, $names, true)) {
                $names[] = $candidate;
            }
        }

        return $names === [] ? null : $names;
    }

    /**
     * @param array<string, mixed> $anime
     *
     * @return array<string, string>|null
     */
    private static function buildDescriptions(array $anime): ?array
    {
        $raw = $anime['description'] ?? null;
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $cleaned = DescriptionCleaner::clean($raw);

        return $cleaned === '' ? null : ['ru' => $cleaned];
    }

    /**
     * @param array<string, mixed> $anime
     *
     * @return list<string>|null
     */
    private static function buildStudios(array $anime): ?array
    {
        $studios = \is_array($anime['studios'] ?? null) ? $anime['studios'] : [];

        $names = [];
        foreach ($studios as $studio) {
            $name = \is_array($studio) ? $studio['name'] ?? null : null;
            if (\is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names === [] ? null : $names;
    }

    private static function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $date !== false && $date->format('Y-m-d') === $raw ? $date : null;
    }
}
