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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Tests;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Model\AnimeName;
use AnimeDb\PluginContracts\Model\AnimeType;
use AnimeDb\PluginContracts\Model\Demographic;
use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\NameRole;
use AnimeDb\PluginContracts\Model\ThemeCode;
use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\PluginContracts\Sync\SyncItem;
use AnimeDb\PluginContracts\Sync\SyncRemovalInterface;
use AnimeDb\PluginContracts\Sync\SyncStatus;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\NotFoundHttpException;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\UnauthorizedHttpException;
use AnimeDb\Plugins\AnimedbMyanimelist\MalFiller;
use AnimeDb\Plugins\AnimedbMyanimelist\OAuth\MalOAuthClient;
use AnimeDb\Plugins\AnimedbMyanimelist\Sync\MalAuthRetrier;
use PHPUnit\Framework\TestCase;

final class MalFillerTest extends TestCase
{
    private const STUB_MANIFEST_ID = 'animedb-myanimelist-stub';

    public function testFindReturnsEmptyArrayWithoutHttpCallWhenQueryIsEmpty(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::never())->method('get');

        self::assertSame([], $this->buildFiller($client)->find(''));
    }

    public function testFindReturnsEmptyArrayWithoutHttpCallWhenQueryIsShorterThanMinimum(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::never())->method('get');

        // MIN_QUERY_LENGTH is 3; 2 characters must still short-circuit.
        self::assertSame([], $this->buildFiller($client)->find('ok'));
    }

    public function testFindSendsQueryAtExactlyTheMinimumLength(): void
    {
        $sentQuery = null;
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())
            ->method('get')
            ->willReturnCallback(function (string $path, array $query) use (&$sentQuery): array {
                $sentQuery = $query;

                return ['data' => []];
            });

        $this->buildFiller($client)->find('kon');

        self::assertSame('kon', $sentQuery['q'] ?? null);
    }

    public function testFindTruncatesQueryLongerThanMaximum(): void
    {
        $tooLong = str_repeat('a', MalFiller::MAX_QUERY_LENGTH + 10);
        $truncated = str_repeat('a', MalFiller::MAX_QUERY_LENGTH);

        $sentQuery = null;
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())
            ->method('get')
            ->willReturnCallback(function (string $path, array $query) use (&$sentQuery): array {
                $sentQuery = $query;

                return ['data' => []];
            });

        $this->buildFiller($client)->find($tooLong);

        self::assertSame($truncated, $sentQuery['q'] ?? null);
    }

    /**
     * Confirms truncation counts characters, not bytes: each `ナ` is 3 bytes in UTF-8, so a
     * byte-based `substr()` would cut mid-character well before 64 of them and could corrupt
     * the query. Limits were confirmed live against the MyAnimeList API with exactly this kind
     * of multibyte query (64 accepted, 65 rejected).
     */
    public function testFindTruncatesMultibyteQueryByCharacterNotByte(): void
    {
        $tooLong = str_repeat('ナ', MalFiller::MAX_QUERY_LENGTH + 5);
        $truncated = str_repeat('ナ', MalFiller::MAX_QUERY_LENGTH);

        $sentQuery = null;
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())
            ->method('get')
            ->willReturnCallback(function (string $path, array $query) use (&$sentQuery): array {
                $sentQuery = $query;

                return ['data' => []];
            });

        $this->buildFiller($client)->find($tooLong);

        self::assertSame($truncated, $sentQuery['q'] ?? null);
    }

    public function testFindMapsCandidatesFromARealSearchResponse(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(self::narutoSearchFixture());

        $candidates = $this->buildFiller($client)->find('naruto');

        self::assertEquals(
            new SearchByPluginCandidate(self::STUB_MANIFEST_ID, 'Naruto', '20'),
            $candidates[0],
        );
        self::assertCount(10, $candidates);
    }

    public function testFindReturnsEmptyArrayWhenNothingMatches(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(['data' => []]);

        self::assertSame([], $this->buildFiller($client)->find('does not exist'));
    }

    public function testFindSkipsItemsWithAMissingNodeOrAnInvalidTitleOrId(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(['data' => [
            // no `node` at all.
            ['foo' => 'bar'],
            // `node` present but no `title`.
            ['node' => ['id' => 1]],
            // `title` is an empty string.
            ['node' => ['id' => 2, 'title' => '']],
            // `id` missing entirely.
            ['node' => ['title' => 'No Id']],
            // `id` is not an int: an array, a bool, a numeric string, zero, and a negative int.
            ['node' => ['id' => ['nested'], 'title' => 'Array Id']],
            ['node' => ['id' => true, 'title' => 'Bool Id']],
            ['node' => ['id' => '3', 'title' => 'String Id']],
            ['node' => ['id' => 0, 'title' => 'Zero Id']],
            ['node' => ['id' => -1, 'title' => 'Negative Id']],
            // the only valid item.
            ['node' => ['id' => 4, 'title' => 'Valid']],
        ]]);

        $candidates = $this->buildFiller($client)->find('query');

        self::assertEquals(
            [new SearchByPluginCandidate(self::STUB_MANIFEST_ID, 'Valid', '4')],
            $candidates,
        );
    }

    /**
     * `$externalId` is attacker-controlled (a stored id, or whatever a caller passes), and is
     * interpolated into the request path — so a value that is not a bare positive integer must
     * be rejected before a request is ever made, rather than reach {@see MalApiClient::get()}
     * and rewrite the path/query (e.g. a `../` segment or a second `?`).
     *
     * @dataProvider provideMalformedExternalIds
     */
    public function testFindByIdReturnsNullForMalformedExternalIdWithoutHttpCall(string $externalId): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::never())->method('get');

        self::assertNull($this->buildFiller($client)->findById($externalId));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedExternalIds(): iterable
    {
        yield 'path traversal' => ['1/../../users/@me'];
        yield 'extra query string' => ['1?fields=foo'];
        yield 'fragment' => ['1#frag'];
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'leading zero' => ['01'];
        yield 'non-numeric' => ['abc'];
    }

    public function testFindByIdReturnsNullWhenApiRespondsNotFound(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willThrowException(new NotFoundHttpException('MyAnimeList API responded with HTTP 404.'));

        self::assertNull($this->buildFiller($client)->findById('3455'));
    }

    public function testFindByIdReturnsNullWhenTitleIsMissingOrEmpty(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(['id' => 3455]);

        self::assertNull($this->buildFiller($client)->findById('3455'));

        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(['id' => 3455, 'title' => '']);

        self::assertNull($this->buildFiller($client)->findById('3455'));
    }

    /**
     * Uses a real captured card ({@see self::cardFixture()}) rather than a handwritten array:
     * `alternative_titles` and `synopsis` are exactly the fields
     * {@see MalFiller::buildAlternativeNames()}/`buildDescriptions()` map, and this fixture also
     * carries the `Ecchi` 18+ genre MAL's taxonomy mixes in among ordinary ones, next to `Harem`
     * and `School` (themes) and `Shounen` (demographic) — none of the contract's dictionaries
     * has a case for an 18+ rating, so {@see \AnimeDb\Plugins\AnimedbMyanimelist\Mapping\GenreMapper}
     * drops it rather than failing the whole lookup.
     */
    public function testFindByIdMapsFullCard(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())
            ->method('get')
            ->with('/anime/3455', self::callback(static function (array $query): bool {
                $fields = explode(',', $query['fields'] ?? '');
                foreach ([
                    'alternative_titles', 'synopsis', 'genres', 'media_type', 'start_date',
                    'end_date', 'num_episodes', 'average_episode_duration', 'studios',
                    'main_picture', 'pictures',
                ] as $field) {
                    if (!\in_array($field, $fields, true)) {
                        return false;
                    }
                }

                return true;
            }))
            ->willReturn(self::cardFixture('card_3455.json'));

        $data = $this->buildFiller($client)->findById('3455');

        self::assertNotNull($data);
        self::assertSame('To LOVE-Ru', $data->title);
        self::assertEquals([
            new AnimeName('To LOVEる -とらぶる-', 'ja', NameRole::Official),
            new AnimeName('To Love Ru', 'en', NameRole::Official),
            new AnimeName('Toraburu', null, NameRole::Synonym),
            new AnimeName('Love Trouble', null, NameRole::Synonym),
        ], $data->alternativeNames);
        self::assertIsArray($data->descriptions);
        self::assertSame(['en'], array_keys($data->descriptions));
        self::assertStringEndsWith('defy the laws of physics.', $data->descriptions['en']);
        self::assertStringNotContainsString('[Written by MAL Rewrite]', $data->descriptions['en']);
        self::assertSame([GenreCode::Comedy, GenreCode::Romance, GenreCode::SciFi], $data->genres);
        self::assertSame([ThemeCode::Harem, ThemeCode::School], $data->themes);
        self::assertSame(Demographic::Shounen, $data->demographic);
        self::assertSame(['Xebec'], $data->studios);
        self::assertSame(AnimeType::Tv, $data->type);
        self::assertEquals(new \DateTimeImmutable('2008-04-04'), $data->datePremiere);
        self::assertEquals(new \DateTimeImmutable('2008-09-26'), $data->dateEnd);
        self::assertSame(25, $data->durationMinutes);
        self::assertSame(26, $data->episodesCount);
        self::assertSame('https://cdn.myanimelist.net/images/anime/1292/147431l.jpg', $data->cover);
        self::assertNotNull($data->images);
        self::assertCount(11, $data->images);
        self::assertSame('https://cdn.myanimelist.net/images/anime/10/20614l.jpg', $data->images[0]);
        self::assertNull($data->countries);
    }

    public function testFindByIdCompletesAPartialEndDateToTheLastDayOfItsPeriod(): void
    {
        $fixture = self::cardFixture('card_3455.json');
        $fixture['end_date'] = '2008-09';

        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn($fixture);

        $data = $this->buildFiller($client)->findById('3455');

        self::assertEquals(new \DateTimeImmutable('2008-04-04'), $data->datePremiere);
        self::assertEquals(new \DateTimeImmutable('2008-09-30'), $data->dateEnd);
    }

    /**
     * `end_date` is only `2008` (year-only, completed to `2008-12-31` by {@see
     * \AnimeDb\Plugins\AnimedbMyanimelist\Mapping\DateParser}), while `start_date` is a fully
     * dated `2009-01-10` from a later season of the same card — a real mismatch rather than a
     * contrived one, since MyAnimeList lets these two fields come from different editorial
     * passes. The rounded `dateEnd` lands before `datePremiere`, so the guard must drop
     * `dateEnd` and keep `datePremiere`, instead of handing the host a card it rejects outright.
     */
    public function testFindByIdDropsDateEndWhenRoundingPutsItBeforeDatePremiere(): void
    {
        $fixture = self::cardFixture('card_3455.json');
        $fixture['start_date'] = '2009-01-10';
        $fixture['end_date'] = '2008';

        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn($fixture);

        $data = $this->buildFiller($client)->findById('3455');

        self::assertEquals(new \DateTimeImmutable('2009-01-10'), $data->datePremiere);
        self::assertNull($data->dateEnd);
    }

    public function testFindByIdConvertsAverageEpisodeDurationFromSecondsToMinutes(): void
    {
        $fixture = self::cardFixture('card_3455.json');
        $fixture['average_episode_duration'] = 1470;

        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn($fixture);

        // 1470 seconds = 24.5 minutes, rounded to the nearest minute (half away from zero).
        self::assertSame(25, $this->buildFiller($client)->findById('3455')->durationMinutes);
    }

    /**
     * `average_episode_duration` under 30 seconds (short promos, CMs, teasers that MyAnimeList
     * carries as regular entries) rounds down to 0 minutes, which the application rejects as an
     * invalid duration. Any positive number of seconds must therefore floor to at least 1 minute.
     *
     * @dataProvider provideShortAverageEpisodeDurations
     */
    public function testFindByIdFloorsShortAverageEpisodeDurationToOneMinute(int $seconds, int $expectedMinutes): void
    {
        $fixture = self::cardFixture('card_3455.json');
        $fixture['average_episode_duration'] = $seconds;

        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn($fixture);

        self::assertSame($expectedMinutes, $this->buildFiller($client)->findById('3455')->durationMinutes);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function provideShortAverageEpisodeDurations(): iterable
    {
        yield '20 seconds rounds to 0 but floors to 1 minute' => [20, 1];
        yield '29 seconds rounds to 0 but floors to 1 minute' => [29, 1];
        yield '30 seconds rounds to 1 minute' => [30, 1];
        yield '90 seconds rounds up to 2 minutes' => [90, 2];
    }

    public function testFindByIdTreatsZeroAverageEpisodeDurationAsUnknown(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(self::cardFixture('card_59068.json'));

        self::assertNull($this->buildFiller($client)->findById('59068')->durationMinutes);
    }

    public function testFindByIdTreatsZeroEpisodesAsUnknown(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(self::cardFixture('card_59068.json'));

        self::assertNull($this->buildFiller($client)->findById('59068')->episodesCount);
    }

    public function testFindByIdDropsTypeForUnknownMediaType(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('get')->willReturn(self::cardFixture('card_63143.json'));

        self::assertNull($this->buildFiller($client)->findById('63143')->type);
    }

    public function testFindByIdSendsRequestWithoutAuthorizationBearer(): void
    {
        $capturedBearer = 'not-called';
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())
            ->method('get')
            ->willReturnCallback(function (string $path, array $query = [], ?callable $onHeartbeat = null, ?string $bearer = null) use (&$capturedBearer): array {
                $capturedBearer = $bearer;

                return self::cardFixture('card_3455.json');
            });

        $this->buildFiller($client)->findById('3455');

        self::assertNull($capturedBearer);
    }

    public function testGetFillableFieldsMatchesExactlyWhatFindByIdFills(): void
    {
        $filler = $this->buildFiller($this->createMock(MalApiClient::class));

        self::assertSame([
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
        ], $filler->getFillableFields());
    }

    public function testResolveExternalIdMatchesMyAnimeListDomainsAndPaths(): void
    {
        $filler = $this->buildFiller($this->createMock(MalApiClient::class));

        self::assertSame('20', $filler->resolveExternalId(['https://myanimelist.net/anime/20/Naruto']));
        self::assertSame('20', $filler->resolveExternalId(['https://myanimelist.net/anime.php?id=20']));
        self::assertNull($filler->resolveExternalId(['https://shikimori.io/animes/20-naruto']));
    }

    public function testPushCallsUpdateListStatusExactlyOnceWithMappedStatusAndEpisodes(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())
            ->method('updateListStatus')
            ->with('the-token', '20', 'completed', 13)
            ->willReturn(['status' => 'completed', 'num_episodes_watched' => 13, 'updated_at' => '2026-08-10T12:05:00+00:00']);

        $filler = $this->buildFiller($client, $this->realAuthRetrier('the-token'));

        $result = $filler->push(new SyncItem('20', SyncStatus::Completed, 'Naruto', AnimeType::Tv, null, 13));

        self::assertEquals(
            new SyncItem('20', SyncStatus::Completed, 'Naruto', null, new \DateTimeImmutable('2026-08-10T12:05:00+00:00'), 13),
            $result,
        );
    }

    /**
     * `is_rewatching=false` is itself guaranteed by
     * {@see MalApiClient::updateListStatus()} (covered in `MalApiClientTest`); this test
     * guards the one-call invariant at the `push()` level — a retry, a find-or-create
     * sequence, or any second write would multiply it.
     */
    public function testPushMakesExactlyOneWriteCall(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())->method('updateListStatus')->willReturn([]);

        $filler = $this->buildFiller($client, $this->realAuthRetrier('the-token'));
        $filler->push(new SyncItem('20', SyncStatus::Watching, 'Naruto', null, null, 5));
    }

    public function testPushFallsBackToSentValuesWhenResponseOmitsConfirmation(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('updateListStatus')->willReturn(['status' => 'watching']);

        $filler = $this->buildFiller($client, $this->realAuthRetrier('the-token'));

        $result = $filler->push(new SyncItem('20', SyncStatus::Watching, 'Naruto', AnimeType::Movie, null, 5));

        self::assertEquals(new SyncItem('20', SyncStatus::Watching, 'Naruto', null, null, 5), $result);
    }

    public function testPushRetriesOnceAfterUnauthorizedThroughMalAuthRetrier(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->method('accessToken')->willReturnOnConsecutiveCalls('expired-token', 'fresh-token');
        $oauth->expects(self::once())->method('refreshAccessToken');

        $calls = 0;
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::exactly(2))
            ->method('updateListStatus')
            ->willReturnCallback(function (string $bearer) use (&$calls): array {
                ++$calls;
                if ($calls === 1) {
                    self::assertSame('expired-token', $bearer);

                    throw new UnauthorizedHttpException('MyAnimeList API responded with HTTP 401.');
                }

                self::assertSame('fresh-token', $bearer);

                return ['status' => 'watching', 'num_episodes_watched' => 5, 'updated_at' => '2026-08-10T12:00:00+00:00'];
            });

        $filler = $this->buildFiller($client, new MalAuthRetrier($oauth));

        $result = $filler->push(new SyncItem('20', SyncStatus::Watching, 'Naruto', null, null, 1));

        self::assertSame(5, $result->watchedEpisodes);
    }

    public function testPullYieldsMappedItemsFromAPage(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('fetchAnimeListPage')->willReturn([
            'items' => [
                [
                    'node' => ['id' => 20, 'title' => 'Naruto'],
                    'list_status' => ['status' => 'watching', 'num_episodes_watched' => 55, 'updated_at' => '2026-08-10T09:30:00+00:00'],
                ],
            ],
            'hasNext' => false,
        ]);

        $filler = $this->buildFiller($client, $this->realAuthRetrier('the-token'));

        $items = iterator_to_array($filler->pull());

        self::assertEquals(
            [new SyncItem('20', SyncStatus::Watching, 'Naruto', null, new \DateTimeImmutable('2026-08-10T09:30:00+00:00'), 55)],
            $items,
        );
    }

    public function testPullMapsIsRewatchingTrueToWatchingRegardlessOfStatus(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('fetchAnimeListPage')->willReturn([
            'items' => [
                ['node' => ['id' => 20, 'title' => 'Naruto'], 'list_status' => ['status' => 'completed', 'is_rewatching' => true]],
            ],
            'hasNext' => false,
        ]);

        $filler = $this->buildFiller($client, $this->realAuthRetrier('the-token'));

        $items = iterator_to_array($filler->pull());

        self::assertSame(SyncStatus::Watching, $items[0]->status);
    }

    /**
     * Documents a known round-trip limitation (see README's "Пересмотр" section): a record
     * that is `completed` + `is_rewatching=true` on MyAnimeList comes back from pull() as
     * `SyncStatus::Watching` (the contract has no "completed and rewatching" status), and
     * pushing that same item back sends `status=watching, is_rewatching=false` — moving the
     * record on MyAnimeList from "Completed" to "Watching", not merely clearing the rewatch
     * flag. {@see SyncItem} carries no field to recover the original `completed` status from.
     */
    public function testPushAfterPullOfARewatchedCompletedItemOverwritesStatusOnMal(): void
    {
        $pullClient = $this->createMock(MalApiClient::class);
        $pullClient->method('fetchAnimeListPage')->willReturn([
            'items' => [
                ['node' => ['id' => 20, 'title' => 'Naruto'], 'list_status' => ['status' => 'completed', 'is_rewatching' => true]],
            ],
            'hasNext' => false,
        ]);
        $pulledItem = iterator_to_array($this->buildFiller($pullClient, $this->realAuthRetrier('the-token'))->pull())[0];

        self::assertSame(SyncStatus::Watching, $pulledItem->status);

        $pushClient = $this->createMock(MalApiClient::class);
        $pushClient->expects(self::once())
            ->method('updateListStatus')
            ->with(self::anything(), '20', 'watching', $pulledItem->watchedEpisodes)
            ->willReturn([]);

        $this->buildFiller($pushClient, $this->realAuthRetrier('the-token'))->push($pulledItem);
    }

    /**
     * @return iterable<string, array{string|null, AnimeType|null}>
     */
    public static function pullMediaTypeProvider(): iterable
    {
        yield 'tv' => ['tv', AnimeType::Tv];
        yield 'movie' => ['movie', AnimeType::Movie];
        yield 'pv' => ['pv', null];
        yield 'absent' => [null, null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pullMediaTypeProvider')]
    public function testPullTakesTypeFromMediaType(?string $mediaType, ?AnimeType $expected): void
    {
        $node = ['id' => 4, 'title' => 'Valid'];
        if ($mediaType !== null) {
            $node['media_type'] = $mediaType;
        }

        $client = $this->createMock(MalApiClient::class);
        $client->method('fetchAnimeListPage')->willReturn([
            'items' => [['node' => $node, 'list_status' => ['status' => 'plan_to_watch']]],
            'hasNext' => false,
        ]);

        $items = iterator_to_array($this->buildFiller($client, $this->realAuthRetrier('the-token'))->pull());

        self::assertCount(1, $items);
        self::assertSame($expected, $items[0]->type);
    }

    public function testPullSkipsItemsWithoutIdTitleOrUnknownStatus(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->method('fetchAnimeListPage')->willReturn([
            'items' => [
                ['node' => ['title' => 'No Id'], 'list_status' => ['status' => 'watching']],
                ['node' => ['id' => 1], 'list_status' => ['status' => 'watching']],
                ['node' => ['id' => 2, 'title' => 'Unknown Status'], 'list_status' => ['status' => 'bogus']],
                ['node' => ['id' => 3, 'title' => 'No List Status']],
                ['node' => ['id' => 0, 'title' => 'Zero Id'], 'list_status' => ['status' => 'watching']],
                ['node' => ['id' => '1/../x', 'title' => 'Path-Like Id'], 'list_status' => ['status' => 'watching']],
                ['node' => ['id' => 5, 'title' => ''], 'list_status' => ['status' => 'watching']],
                ['node' => ['id' => 4, 'title' => 'Valid'], 'list_status' => ['status' => 'plan_to_watch']],
            ],
            'hasNext' => false,
        ]);

        $filler = $this->buildFiller($client, $this->realAuthRetrier('the-token'));

        $items = iterator_to_array($filler->pull());

        self::assertEquals([new SyncItem('4', SyncStatus::Plan, 'Valid', null)], $items);
    }

    /**
     * The next page must be requested only when {@see MalApiClient::fetchAnimeListPage()}
     * reports `hasNext: true` (which itself is driven by the presence of `paging.next`, not
     * by page length — see that class) — and a page shorter than the limit must not stop
     * pull() on its own.
     */
    public function testPullRequestsNextPageWhenHasNextIsTrueEvenForAShortPage(): void
    {
        $calls = [];
        $client = $this->createMock(MalApiClient::class);
        $client->method('fetchAnimeListPage')->willReturnCallback(
            function (string $bearer, int $offset, int $limit) use (&$calls): array {
                $calls[] = [$offset, $limit];

                // A single-item (short) page with `hasNext: true` must still be followed.
                return \count($calls) === 1
                    ? ['items' => [['node' => ['id' => 1, 'title' => 'First'], 'list_status' => ['status' => 'watching']]], 'hasNext' => true]
                    : ['items' => [], 'hasNext' => false];
            },
        );

        $filler = $this->buildFiller($client, $this->realAuthRetrier('the-token'));

        $items = iterator_to_array($filler->pull());

        self::assertCount(1, $items);
        self::assertCount(2, $calls);
        // The second page's offset must step by the limit actually sent on the first call, not
        // by the number of items actually received on the first page (one item) — see
        // MalFiller::pull()'s doc. Comparing against the sent `$limit` (rather than a hardcoded
        // literal) catches a drift between the offset step and the page size constant.
        self::assertSame(0, $calls[0][0]);
        self::assertSame($calls[0][1], $calls[1][0], 'offset must advance by the limit actually sent');
        self::assertSame($calls[0][1], $calls[1][1]);
    }

    public function testPullStopsWhenHasNextIsFalse(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())->method('fetchAnimeListPage')->willReturn([
            'items' => [['node' => ['id' => 1, 'title' => 'Only'], 'list_status' => ['status' => 'watching']]],
            'hasNext' => false,
        ]);

        $filler = $this->buildFiller($client, $this->realAuthRetrier('the-token'));

        self::assertCount(1, iterator_to_array($filler->pull()));
    }

    public function testPullIsLazyAndOnlyFetchesPagesAsTheyAreConsumed(): void
    {
        $calls = 0;
        $client = $this->createMock(MalApiClient::class);
        $client->method('fetchAnimeListPage')->willReturnCallback(function () use (&$calls): array {
            ++$calls;

            return [
                'items' => [['node' => ['id' => 1, 'title' => 'Filler'], 'list_status' => ['status' => 'watching']]],
                'hasNext' => true,
            ];
        });

        $filler = $this->buildFiller($client, $this->realAuthRetrier('the-token'));

        $generator = $filler->pull();
        self::assertSame(0, $calls, 'pull() must not issue any request before the caller starts iterating');

        $generator->current();
        self::assertSame(1, $calls, 'the first page is fetched once the caller asks for the first item');
    }

    public function testPullRetriesOnceAfterUnauthorizedThroughMalAuthRetrier(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->method('accessToken')->willReturnOnConsecutiveCalls('expired-token', 'fresh-token');
        $oauth->expects(self::once())->method('refreshAccessToken');

        $calls = 0;
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::exactly(2))
            ->method('fetchAnimeListPage')
            ->willReturnCallback(function (string $bearer) use (&$calls): array {
                ++$calls;
                if ($calls === 1) {
                    self::assertSame('expired-token', $bearer);

                    throw new UnauthorizedHttpException('MyAnimeList API responded with HTTP 401.');
                }

                self::assertSame('fresh-token', $bearer);

                return ['items' => [['node' => ['id' => 1, 'title' => 'Naruto'], 'list_status' => ['status' => 'watching']]], 'hasNext' => false];
            });

        $filler = $this->buildFiller($client, new MalAuthRetrier($oauth));

        $items = iterator_to_array($filler->pull());

        self::assertCount(1, $items);
    }

    public function testFillerImplementsSyncRemovalInterface(): void
    {
        self::assertInstanceOf(
            SyncRemovalInterface::class,
            $this->buildFiller($this->createMock(MalApiClient::class)),
        );
    }

    public function testRemoveCallsDeleteListStatusExactlyOnceWithExternalId(): void
    {
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())->method('deleteListStatus')->with('the-token', '5114');

        $this->buildFiller($client, $this->realAuthRetrier('the-token'))->remove('5114');
    }

    public function testRemoveRetriesOnceAfterUnauthorizedThroughMalAuthRetrier(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->method('accessToken')->willReturnOnConsecutiveCalls('expired-token', 'fresh-token');
        $oauth->expects(self::once())->method('refreshAccessToken');

        $bearers = [];
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::exactly(2))
            ->method('deleteListStatus')
            ->willReturnCallback(function (string $bearer, string $id) use (&$bearers): void {
                self::assertSame('5114', $id);
                $bearers[] = $bearer;
                if (\count($bearers) === 1) {
                    throw new UnauthorizedHttpException('MyAnimeList API responded with HTTP 401.');
                }
            });

        $this->buildFiller($client, new MalAuthRetrier($oauth))->remove('5114');

        self::assertSame(['expired-token', 'fresh-token'], $bearers);
    }

    public function testRemoveThrowsReauthRequiredWhenSessionIsDead(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->method('accessToken')->willReturn('old-token');
        $oauth->method('refreshAccessToken')->willThrowException(new \LogicException('No OAuth refresh token stored.'));
        $oauth->expects(self::once())->method('disconnect');

        $client = $this->createMock(MalApiClient::class);
        $client->method('deleteListStatus')->willThrowException(new UnauthorizedHttpException('HTTP 401'));

        $this->expectException(ReauthRequiredException::class);
        $this->buildFiller($client, new MalAuthRetrier($oauth))->remove('5114');
    }

    public function testRemoveThrowsReauthRequiredWithoutHttpCallWhenNotConnected(): void
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->method('accessToken')->willReturn(null);

        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::never())->method('deleteListStatus');

        $this->expectException(ReauthRequiredException::class);
        $this->buildFiller($client, new MalAuthRetrier($oauth))->remove('5114');
    }

    public function testRemoveDoesNotSurfaceNotOnListFromClient(): void
    {
        // The client turns HTTP 404 into a normal return; remove() must stay silent as well.
        $client = $this->createMock(MalApiClient::class);
        $client->expects(self::once())->method('deleteListStatus');

        $this->buildFiller($client, $this->realAuthRetrier('the-token'))->remove('5114');
        $this->addToAssertionCount(1);
    }

    private function realAuthRetrier(string $bearer): MalAuthRetrier
    {
        $oauth = $this->createMock(MalOAuthClient::class);
        $oauth->method('accessToken')->willReturn($bearer);
        $oauth->expects(self::never())->method('refreshAccessToken');

        return new MalAuthRetrier($oauth);
    }

    private function buildFiller(MalApiClient $client, ?MalAuthRetrier $authRetrier = null): MalFiller
    {
        return new MalFiller($client, $authRetrier ?? $this->createMock(MalAuthRetrier::class), $this->stubOwnManifest());
    }

    private function stubOwnManifest(): OwnManifestInterface
    {
        $manifest = $this->createMock(OwnManifestInterface::class);
        $manifest->method('id')->willReturn(self::STUB_MANIFEST_ID);

        return $manifest;
    }

    /**
     * @return array<string, mixed>
     */
    private static function narutoSearchFixture(): array
    {
        $json = file_get_contents(__DIR__.'/Fixture/search_naruto.json');
        \assert($json !== false);

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $fixture;
    }

    /**
     * @return array<string, mixed>
     */
    private static function cardFixture(string $file): array
    {
        $json = file_get_contents(__DIR__.'/Fixture/'.$file);
        \assert($json !== false);

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $fixture;
    }
}
