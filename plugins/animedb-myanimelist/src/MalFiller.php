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

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Model\AnimeName;
use AnimeDb\PluginContracts\Model\NameRole;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\Plugins\AnimedbMyanimelist\ExternalId\MalIdResolver;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\NotFoundHttpException;
use AnimeDb\Plugins\AnimedbMyanimelist\Mapping\AnimeTypeMapper;
use AnimeDb\Plugins\AnimedbMyanimelist\Mapping\DateParser;
use AnimeDb\Plugins\AnimedbMyanimelist\Mapping\GenreMapper;
use AnimeDb\Plugins\AnimedbMyanimelist\Mapping\SynopsisCleaner;

/**
 * Источник MyAnimeList: поиск аниме по названию, распознавание id по уже прикреплённым к
 * записи ссылкам и заполнение карточки, через анонимные чтения MyAnimeList API v2
 * (`find()`/`resolveExternalId()`/`findById()`).
 *
 * Единственный класс плагина, реализующий {@see FillerInterface}: следующая задача серии
 * расширяет именно его до `SyncInterface` (синхронизация watch-листа), а не добавляет второй
 * filler-совместимый класс на тот же id плагина — это дало бы коллизию тегов
 * `app.filler`/`app.sync` на хосте.
 *
 * `findById()` — адаптированная для полей MyAnimeList копия
 * {@see \AnimeDb\Plugins\AnimedbShikimori\ShikimoriFiller::findById()}, а не общий код между
 * плагинами: оба плагина остаются независимыми друг от друга и от контракта.
 *
 * Тяжёлый HTTP вынесен в {@see MalApiClient}.
 */
final class MalFiller implements FillerInterface
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

    /**
     * `related_anime`/`recommendations` are requested because they are part of the one card
     * endpoint this plugin calls, but this plugin's `findById()` does not read them — the
     * widgets that show them are a later task in this series.
     */
    private const CARD_FIELDS = 'id,title,main_picture,alternative_titles,start_date,end_date,'
        .'synopsis,media_type,status,genres,num_episodes,average_episode_duration,studios,'
        .'pictures,related_anime,recommendations';

    private const SECONDS_PER_MINUTE = 60;

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

    /**
     * Fetches and maps a single anime card by its MyAnimeList id, anonymously (no `Authorization`
     * header — {@see MalApiClient::get()}'s `$bearer` is deliberately left unset, see the class
     * doc).
     *
     * `$externalId` is attacker-controlled (it comes from a record's stored id or from
     * {@see resolveExternalId()}, but also from whatever a caller passes directly) and is
     * interpolated into the request path below, so it is restricted to a bare positive integer
     * before it ever reaches {@see MalApiClient::get()} — anything else (path segments,
     * a second `?query`, a `#fragment`) is rejected without making a request.
     *
     * A 404 from the API (an id MyAnimeList does not have) is caught and mapped to `null`
     * — "not found" — same contract as {@see \AnimeDb\Plugins\AnimedbShikimori\ShikimoriFiller::findById()}'s
     * empty result, see {@see NotFoundHttpException}.
     *
     * `countries` is deliberately left unset: the card endpoint has no field for production
     * country.
     */
    public function findById(string $externalId): ?PluginAnimeData
    {
        if (preg_match('/^[1-9]\d*$/', $externalId) !== 1) {
            return null;
        }

        try {
            $anime = $this->client->get('/anime/'.$externalId, ['fields' => self::CARD_FIELDS]);
        } catch (NotFoundHttpException) {
            return null;
        }

        $title = $anime['title'] ?? null;
        if (!\is_string($title) || $title === '') {
            return null;
        }

        $genres = \is_array($anime['genres'] ?? null) ? array_values($anime['genres']) : [];
        $mappedGenres = GenreMapper::map($genres);

        $datePremiere = DateParser::parseStart(\is_string($anime['start_date'] ?? null) ? $anime['start_date'] : null);
        $dateEnd = DateParser::parseEnd(\is_string($anime['end_date'] ?? null) ? $anime['end_date'] : null);
        // The host treats a title as already aired once `dateEnd <= now` and rejects
        // `dateEnd < datePremiere` outright; a partial `end_date` rounded up to the end of its
        // period (see DateParser) can still land before a fully-dated `start_date` within the
        // same card, so `dateEnd` is dropped rather than risk either outcome.
        if ($datePremiere !== null && $dateEnd !== null && $dateEnd < $datePremiere) {
            $dateEnd = null;
        }

        return new PluginAnimeData(
            title: $title,
            alternativeNames: self::buildAlternativeNames($title, $anime),
            descriptions: self::buildDescriptions($anime),
            genres: $mappedGenres['genres'] === [] ? null : $mappedGenres['genres'],
            themes: $mappedGenres['themes'] === [] ? null : $mappedGenres['themes'],
            demographic: $mappedGenres['demographics'][0] ?? null,
            studios: self::buildStudios($anime),
            type: AnimeTypeMapper::map(\is_string($anime['media_type'] ?? null) ? $anime['media_type'] : null),
            datePremiere: $datePremiere,
            dateEnd: $dateEnd,
            durationMinutes: self::buildDurationMinutes($anime),
            episodesCount: \is_int($anime['num_episodes'] ?? null) && $anime['num_episodes'] > 0 ? $anime['num_episodes'] : null,
            cover: self::buildCover($anime),
            images: self::buildImages($anime),
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
            'images',
        ];
    }

    /**
     * Lays each source field out onto its own (locale, role) pair rather than flattening them
     * into one untyped list, by the same reasoning as
     * {@see \AnimeDb\Plugins\AnimedbShikimori\ShikimoriFiller::buildAlternativeNames()}:
     * `alternative_titles.ja`/`.en` are official titles the source declares in a specific
     * language, `synonyms` is an untyped bucket the source makes no language claim about at
     * all, so its entries keep `locale: null`. MyAnimeList has no Russian title field.
     *
     * @param array<string, mixed> $anime
     *
     * @return AnimeName[]|null
     */
    private static function buildAlternativeNames(string $title, array $anime): ?array
    {
        $altTitles = \is_array($anime['alternative_titles'] ?? null) ? $anime['alternative_titles'] : [];

        $fields = [
            ['value' => $altTitles['ja'] ?? null, 'locale' => 'ja', 'role' => NameRole::Official],
            ['value' => $altTitles['en'] ?? null, 'locale' => 'en', 'role' => NameRole::Official],
        ];

        $synonyms = \is_array($altTitles['synonyms'] ?? null) ? $altTitles['synonyms'] : [];
        foreach ($synonyms as $synonym) {
            $fields[] = ['value' => $synonym, 'locale' => null, 'role' => NameRole::Synonym];
        }

        $seen = [];
        $names = [];
        foreach ($fields as $field) {
            $value = $field['value'];
            if (!\is_string($value) || $value === '' || $value === $title) {
                continue;
            }

            $localeKey = $field['locale'] ?? '';
            if (isset($seen[$localeKey][$value])) {
                continue;
            }
            $seen[$localeKey][$value] = true;

            $names[] = new AnimeName($value, $field['locale'], $field['role']);
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
        $raw = $anime['synopsis'] ?? null;
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $cleaned = SynopsisCleaner::clean($raw);

        return $cleaned === '' ? null : ['en' => $cleaned];
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

    /**
     * @param array<string, mixed> $anime
     *
     * @return list<string>|null
     */
    private static function buildImages(array $anime): ?array
    {
        $pictures = \is_array($anime['pictures'] ?? null) ? $anime['pictures'] : [];

        $urls = [];
        foreach ($pictures as $picture) {
            $url = \is_array($picture) ? $picture['large'] ?? null : null;
            if (\is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return $urls === [] ? null : $urls;
    }

    /**
     * @param array<string, mixed> $anime
     */
    private static function buildCover(array $anime): ?string
    {
        $mainPicture = \is_array($anime['main_picture'] ?? null) ? $anime['main_picture'] : [];
        $url = $mainPicture['large'] ?? null;

        return \is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Converts MyAnimeList's `average_episode_duration` (seconds) to whole minutes, rounded to
     * the nearest minute (`round()`, half away from zero — e.g. 1470 seconds, 24.5 minutes,
     * rounds up to 25). `0` carries no duration information (MyAnimeList's placeholder for a
     * title that has not aired yet), so it maps to `null` rather than a misleading zero,
     * mirroring the `episodesCount` zero guard above.
     *
     * @param array<string, mixed> $anime
     */
    private static function buildDurationMinutes(array $anime): ?int
    {
        $seconds = $anime['average_episode_duration'] ?? null;

        return \is_int($seconds) && $seconds > 0 ? (int) round($seconds / self::SECONDS_PER_MINUTE) : null;
    }
}
