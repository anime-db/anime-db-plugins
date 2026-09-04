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

namespace AnimeDb\Plugins\AnimedbShikimori;

use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Sync\SyncItem;
use AnimeDb\Plugins\AnimedbShikimori\ExternalId\ShikimoriIdResolver;
use AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlClient;
use AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlRequestException;
use AnimeDb\Plugins\AnimedbShikimori\Http\ShikimoriRestClient;
use AnimeDb\Plugins\AnimedbShikimori\Mapping\AnimeTypeMapper;
use AnimeDb\Plugins\AnimedbShikimori\Mapping\DescriptionCleaner;
use AnimeDb\Plugins\AnimedbShikimori\Mapping\GenreMapper;
use AnimeDb\Plugins\AnimedbShikimori\Mapping\SyncStatusMapper;
use AnimeDb\Plugins\AnimedbShikimori\Sync\ShikimoriAuthRetrier;

/**
 * Источник Shikimori: поиск/заполнение карточек аниме через анонимные чтения GraphQL API
 * (`find()`/`findById()`, не читают и не пишут пользовательские данные), плюс — с фазы 4 —
 * синхронизация watch-листа пользователя (`push()`/`pull()`), использующая OAuth-токен фазы 3.
 *
 * Единственный filler-совместимый сервис плагина (`SyncInterface extends FillerInterface`):
 * второй filler-класс на тот же id плагина дал бы коллизию тегов `app.filler`/`app.sync` на
 * хосте, поэтому push()/pull() добавлены сюда же, а не в отдельный класс.
 *
 * Тяжёлый HTTP вынесен в helper-сервисы: {@see GraphQlClient} (анонимные reads фазы 1 и
 * authed pull) и {@see ShikimoriRestClient} (push, REST v2). Общая для обоих транспортов
 * 401→refresh→retry-политика — в {@see ShikimoriAuthRetrier}.
 *
 * Маппинг словарей жанров/тем/демографии не полагается на `genres[].kind` Shikimori — см. класс
 * {@see GenreMapper}. Маппинг статусов синка — {@see SyncStatusMapper}.
 */
final class ShikimoriFiller implements SyncInterface
{
    private const SEARCH_LIMIT = 15;
    private const USER_RATES_PAGE_LIMIT = 50;

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
                screenshots { originalUrl }
            }
        }
        GRAPHQL;

    /**
     * `userId` deliberately omitted: Shikimori defaults it to the Bearer-authenticated user
     * (confirmed via live GraphQL introspection against `shikimori.io` on 2026-08-08 — the
     * argument's own schema description reads "ID of current user is used by default").
     */
    private const USER_RATES_QUERY = <<<'GRAPHQL'
        query($page: PositiveInt, $limit: PositiveInt) {
            userRates(page: $page, limit: $limit) {
                anime { id name }
                status
                episodes
                updatedAt
            }
        }
        GRAPHQL;

    /**
     * Unlike {@see self::USER_RATES_QUERY}, Shikimori's REST v2 `user_rates` `create` action
     * does require `user_id` in the body (confirmed via its live, unauthenticated API doc JSON
     * at `/api/doc/2.0/user_rates` on 2026-08-08 — `user_rate[user_id]` is listed
     * `"required": true`, unlike every other field there). {@see self::resolveUserId()}
     * resolves it once per {@see ShikimoriFiller} instance via this query.
     */
    private const CURRENT_USER_QUERY = <<<'GRAPHQL'
        query {
            currentUser { id }
        }
        GRAPHQL;

    private ?string $cachedUserId = null;

    public function __construct(
        private readonly GraphQlClient $client,
        private readonly ShikimoriRestClient $restClient,
        private readonly ShikimoriAuthRetrier $authRetrier,
        private readonly OwnManifestInterface $ownManifest,
    ) {
    }

    /**
     * Распознаёт id аниме на Shikimori по уже прикреплённым к записи ссылкам.
     *
     * @param string[] $urls
     */
    public function resolveExternalId(array $urls): ?string
    {
        return ShikimoriIdResolver::resolve($urls);
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
        // array_values(): `genres` приходит из json_decode(), а GenreMapper::map() принимает
        // список. Ключи внешнего ответа контролирует Shikimori, не мы.
        $genres = \is_array($anime['genres'] ?? null) ? array_values($anime['genres']) : [];
        $mappedGenres = GenreMapper::map($genres);

        // `countries` is deliberately left unset: Shikimori's `Anime` type has no field for
        // production country (its `origin` is the *source material* — manga, novel, game, etc.
        // — confirmed via live GraphQL/REST introspection against `shikimori.one` on 2026-08-24),
        // so there is nothing to map it from without hardcoding a guess.
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
     * Sets $item's status and watched episode count on Shikimori via REST v2 find-or-create:
     * `POST /api/v2/user_rates` is create-only, so an existing `user_rate` for the target must be
     * found first and updated with `PATCH` instead. This also makes the call idempotent — a
     * repeated push of the same (status, watchedEpisodes) resolves to the existing rate and
     * `PATCH`es it to the same values, a no-op — which is what makes it safe for the host's push
     * message handler to retry.
     *
     * @throws \AnimeDb\PluginContracts\OAuth\ReauthRequiredException no OAuth session, or the
     *                                                                session is confirmed dead
     */
    public function push(SyncItem $item): SyncItem
    {
        $status = SyncStatusMapper::toShikimori($item->status);

        $existingId = $this->authRetrier->call(
            fn (string $bearer): ?string => $this->restClient->findUserRateId($bearer, $this->resolveUserId($bearer), $item->externalId),
        );

        if ($existingId !== null) {
            $response = $this->authRetrier->call(
                fn (string $bearer): array => $this->restClient->updateUserRate($bearer, $existingId, $status, $item->watchedEpisodes),
            );
        } else {
            $response = $this->authRetrier->call(
                fn (string $bearer): array => $this->restClient->createUserRate($bearer, $this->resolveUserId($bearer), $item->externalId, $status, $item->watchedEpisodes),
            );
        }

        // Prefer the source-confirmed `updated_at`/`episodes` from the REST v2 response (see
        // SyncInterface::push()'s doc); fall back to what was actually sent when the response
        // does not carry them.
        $updatedAt = self::parseDateTime($response['updated_at'] ?? null);
        $watchedEpisodes = \is_int($response['episodes'] ?? null) ? $response['episodes'] : $item->watchedEpisodes;

        return new SyncItem($item->externalId, $item->status, $item->title, $updatedAt, $watchedEpisodes);
    }

    /**
     * Pulls the current user's watch list from Shikimori's GraphQL `userRates`, page by page.
     *
     * `SyncInterface::pull()` takes no heartbeat callback, so this method's own laziness is
     * what stands in for one: implemented as a generator, it yields control back to the caller
     * after every item (and issues its one HTTP request per page only when the caller asks for
     * the next one), rather than fetching the whole list up front.
     *
     * @return iterable<SyncItem>
     *
     * @throws \AnimeDb\PluginContracts\OAuth\ReauthRequiredException no OAuth session, or the
     *                                                                session is confirmed dead
     */
    public function pull(): iterable
    {
        $page = 1;

        while (true) {
            $variables = ['page' => $page, 'limit' => self::USER_RATES_PAGE_LIMIT];
            $data = $this->authRetrier->call(
                fn (string $bearer): array => $this->client->query(self::USER_RATES_QUERY, $variables, null, $bearer),
            );
            $userRates = \is_array($data['userRates'] ?? null) ? $data['userRates'] : [];

            foreach ($userRates as $userRate) {
                $item = self::buildSyncItem($userRate);
                if ($item !== null) {
                    yield $item;
                }
            }

            if (\count($userRates) < self::USER_RATES_PAGE_LIMIT) {
                return;
            }

            ++$page;
        }
    }

    /**
     * Resolves the current user's numeric Shikimori id, needed by the REST v2 `create` action
     * (see {@see self::CURRENT_USER_QUERY}'s doc). Cached for the lifetime of this instance: the
     * id itself does not change across a token refresh, so it is fetched at most once even
     * across many {@see self::push()} calls in the same sync run.
     */
    private function resolveUserId(string $bearer): string
    {
        if ($this->cachedUserId !== null) {
            return $this->cachedUserId;
        }

        $data = $this->client->query(self::CURRENT_USER_QUERY, [], null, $bearer);
        $id = $data['currentUser']['id'] ?? null;

        if (!\is_string($id) && !\is_int($id)) {
            throw new GraphQlRequestException('Shikimori GraphQL API did not return the current user id.');
        }

        return $this->cachedUserId = (string) $id;
    }

    /**
     * @param mixed $userRate a single `UserRate` element of {@see self::USER_RATES_QUERY}'s result
     */
    private static function buildSyncItem(mixed $userRate): ?SyncItem
    {
        if (!\is_array($userRate)) {
            return null;
        }

        $anime = \is_array($userRate['anime'] ?? null) ? $userRate['anime'] : null;
        $externalId = $anime['id'] ?? null;
        $title = $anime['name'] ?? null;
        $shikimoriStatus = $userRate['status'] ?? null;

        if (
            (!\is_string($externalId) && !\is_int($externalId))
            || !\is_string($title) || $title === ''
            || !\is_string($shikimoriStatus)
        ) {
            return null;
        }

        $status = SyncStatusMapper::fromShikimori($shikimoriStatus);
        if ($status === null) {
            return null;
        }

        $watchedEpisodes = \is_int($userRate['episodes'] ?? null) ? $userRate['episodes'] : null;

        return new SyncItem((string) $externalId, $status, $title, self::parseDateTime($userRate['updatedAt'] ?? null), $watchedEpisodes);
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

    /**
     * @param array<string, mixed> $anime
     *
     * @return list<string>|null
     */
    private static function buildImages(array $anime): ?array
    {
        $screenshots = \is_array($anime['screenshots'] ?? null) ? $anime['screenshots'] : [];

        $urls = [];
        foreach ($screenshots as $screenshot) {
            $url = \is_array($screenshot) ? $screenshot['originalUrl'] ?? null : null;
            if (\is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return $urls === [] ? null : $urls;
    }

    private static function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $date !== false && $date->format('Y-m-d') === $raw ? $date : null;
    }

    /**
     * Parses a full ISO 8601 timestamp, as returned by both GraphQL's `userRates.updatedAt`
     * and REST v2's `updated_at`. Unlike {@see self::parseDate()}, the exact format is not
     * re-validated by round-tripping — Shikimori's own timestamp formatting is trusted, so any
     * string {@see \DateTimeImmutable} can parse is accepted.
     */
    private static function parseDateTime(mixed $raw): ?\DateTimeImmutable
    {
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
