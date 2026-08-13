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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Model\AnimeType;
use AnimeDb\PluginContracts\Model\Demographic;
use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\ThemeCode;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\PluginContracts\Sync\SyncItem;
use AnimeDb\PluginContracts\Sync\SyncStatus;
use AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlClient;
use AnimeDb\Plugins\AnimedbShikimori\Http\ShikimoriRestClient;
use AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthClient;
use AnimeDb\Plugins\AnimedbShikimori\ShikimoriFiller;
use AnimeDb\Plugins\AnimedbShikimori\Sync\ShikimoriAuthRetrier;
use PHPUnit\Framework\TestCase;

final class ShikimoriFillerTest extends TestCase
{
    private const STUB_MANIFEST_ID = 'animedb-shikimori-stub';

    public function testFindMapsCandidates(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn([
            'animes' => [
                ['id' => '20', 'name' => 'Naruto', 'russian' => 'Наруто', 'kind' => 'tv'],
                ['id' => '813', 'name' => 'Dragon Ball Z', 'russian' => null, 'kind' => 'tv'],
            ],
        ]);

        $filler = $this->buildFiller($client);
        $candidates = $filler->find('naruto');

        self::assertEquals([
            new SearchByPluginCandidate(self::STUB_MANIFEST_ID, 'Naruto', '20'),
            new SearchByPluginCandidate(self::STUB_MANIFEST_ID, 'Dragon Ball Z', '813'),
        ], $candidates);
    }

    public function testFindReturnsEmptyArrayWhenNothingMatches(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => []]);

        $filler = $this->buildFiller($client);

        self::assertSame([], $filler->find('does not exist'));
    }

    public function testFindByIdReturnsNullWhenAnimeIsUnknown(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => []]);

        $filler = $this->buildFiller($client);

        self::assertNull($filler->findById('999999999'));
    }

    public function testFindByIdMapsFullCard(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => [self::narutoFixture()]]);

        $filler = $this->buildFiller($client);
        $data = $filler->findById('20');

        self::assertNotNull($data);
        self::assertSame('Naruto', $data->title);
        self::assertSame(['Наруто'], $data->alternativeNames);
        self::assertSame(['ru' => 'Девятихвостый лис напал на деревню.'], $data->descriptions);
        self::assertSame([GenreCode::Action, GenreCode::AwardWinning], $data->genres);
        self::assertSame([ThemeCode::Isekai], $data->themes);
        self::assertSame(Demographic::Shounen, $data->demographic);
        self::assertSame(['Studio Pierrot'], $data->studios);
        self::assertSame(AnimeType::Tv, $data->type);
        self::assertEquals(new \DateTimeImmutable('2002-10-03'), $data->datePremiere);
        self::assertEquals(new \DateTimeImmutable('2007-02-08'), $data->dateEnd);
        self::assertSame(23, $data->durationMinutes);
        self::assertSame(220, $data->episodesCount);
        self::assertSame('https://shikimori.io/system/animes/original/20.jpg', $data->cover);
        self::assertNull($data->countries);
        self::assertNull($data->images);
    }

    public function testFindByIdDropsAlternativeNameEqualToTitleAndDeduplicates(): void
    {
        $fixture = self::narutoFixture();
        $fixture['name'] = 'Naruto';
        $fixture['russian'] = 'Naruto';
        $fixture['english'] = 'Naruto';
        $fixture['japanese'] = null;
        $fixture['synonyms'] = ['Naruto', 'NARUTO -ナルト-'];

        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => [$fixture]]);

        $filler = $this->buildFiller($client);
        $data = $filler->findById('20');

        self::assertSame(['NARUTO -ナルト-'], $data->alternativeNames);
    }

    public function testFindByIdMapsTvSpecialToSpecial(): void
    {
        $fixture = self::narutoFixture();
        $fixture['kind'] = 'tv_special';

        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => [$fixture]]);

        $filler = $this->buildFiller($client);

        self::assertSame(AnimeType::Special, $filler->findById('20')->type);
    }

    public function testFindByIdDropsTypeForPromotionalVideoKind(): void
    {
        $fixture = self::narutoFixture();
        $fixture['kind'] = 'pv';

        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => [$fixture]]);

        $filler = $this->buildFiller($client);

        self::assertNull($filler->findById('20')->type);
    }

    public function testFindByIdPicksFirstDemographicWhenSeveralPresent(): void
    {
        $fixture = self::narutoFixture();
        $fixture['genres'] = [
            ['id' => 1, 'name' => 'Seinen', 'russian' => 'Сэйнэн', 'kind' => 'demographic'],
            ['id' => 2, 'name' => 'Shounen', 'russian' => 'Сёнэн', 'kind' => 'demographic'],
        ];

        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => [$fixture]]);

        $filler = $this->buildFiller($client);

        self::assertSame(Demographic::Seinen, $filler->findById('20')->demographic);
    }

    public function testFindByIdLeavesDatesNullWhenMissingOrIncomplete(): void
    {
        $fixture = self::narutoFixture();
        $fixture['airedOn'] = null;
        $fixture['releasedOn'] = ['date' => '2007-00-00'];

        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => [$fixture]]);

        $filler = $this->buildFiller($client);
        $data = $filler->findById('20');

        self::assertNull($data->datePremiere);
        self::assertNull($data->dateEnd);
    }

    public function testFindByIdTreatsZeroEpisodesAsUnknown(): void
    {
        $fixture = self::narutoFixture();
        $fixture['episodes'] = 0;

        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => [$fixture]]);

        $filler = $this->buildFiller($client);

        self::assertNull($filler->findById('20')->episodesCount);
    }

    public function testGetFillableFieldsExcludesCountriesAndImages(): void
    {
        $filler = $this->buildFiller($this->createMock(GraphQlClient::class));

        $fields = $filler->getFillableFields();

        self::assertNotContains('countries', $fields);
        self::assertNotContains('images', $fields);
        self::assertContains('title', $fields);
        self::assertContains('genres', $fields);
        self::assertContains('demographic', $fields);
    }

    public function testResolveExternalIdMatchesShikimoriDomainsAndPaths(): void
    {
        $filler = $this->buildFiller($this->createMock(GraphQlClient::class));

        self::assertSame('20', $filler->resolveExternalId(['https://shikimori.io/animes/20-naruto']));
        self::assertSame('20', $filler->resolveExternalId(['https://shikimori.one/animes/z20-naruto']));
        self::assertNull($filler->resolveExternalId(['https://myanimelist.net/anime/20']));
    }

    public function testPushUpdatesExistingUserRateWhenAlreadyOnTheList(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['currentUser' => ['id' => '7']]);

        $restClient = $this->createMock(ShikimoriRestClient::class);
        $restClient->method('findUserRateId')->with('the-token', '7', '20')->willReturn('42');
        $restClient->expects(self::once())->method('updateUserRate')
            ->with('the-token', '42', 'watching', 999)
            ->willReturn(['id' => 42, 'status' => 'watching', 'episodes' => 220, 'updated_at' => '2026-08-10T12:00:00.000+03:00']);
        $restClient->expects(self::never())->method('createUserRate');

        $filler = $this->buildFiller($client, $restClient, $this->realAuthRetrier('the-token'));

        // Shikimori clamps `episodes` to the title's actual episode count: we send 999, it comes back 220.
        $result = $filler->push(new SyncItem('20', SyncStatus::Watching, 'Naruto', null, 999));

        self::assertEquals(
            new SyncItem('20', SyncStatus::Watching, 'Naruto', new \DateTimeImmutable('2026-08-10T12:00:00.000+03:00'), 220),
            $result,
        );
    }

    public function testPushCreatesUserRateWhenNotYetOnTheList(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['currentUser' => ['id' => '7']]);

        $restClient = $this->createMock(ShikimoriRestClient::class);
        $restClient->method('findUserRateId')->willReturn(null);
        $restClient->expects(self::once())->method('createUserRate')
            ->with('the-token', '7', '20', 'completed', 13)
            ->willReturn(['id' => 1, 'status' => 'completed', 'episodes' => 13, 'updated_at' => '2026-08-10T12:05:00.000+03:00']);
        $restClient->expects(self::never())->method('updateUserRate');

        $filler = $this->buildFiller($client, $restClient, $this->realAuthRetrier('the-token'));

        $result = $filler->push(new SyncItem('20', SyncStatus::Completed, 'Naruto', null, 13));

        self::assertEquals(
            new SyncItem('20', SyncStatus::Completed, 'Naruto', new \DateTimeImmutable('2026-08-10T12:05:00.000+03:00'), 13),
            $result,
        );
    }

    public function testPushFallsBackToSentValuesWhenRestResponseOmitsConfirmation(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['currentUser' => ['id' => '7']]);

        $restClient = $this->createMock(ShikimoriRestClient::class);
        $restClient->method('findUserRateId')->willReturn('42');
        $restClient->method('updateUserRate')->willReturn(['id' => 42, 'status' => 'watching']);

        $filler = $this->buildFiller($client, $restClient, $this->realAuthRetrier('the-token'));

        $result = $filler->push(new SyncItem('20', SyncStatus::Watching, 'Naruto', null, 42));

        self::assertEquals(new SyncItem('20', SyncStatus::Watching, 'Naruto', null, 42), $result);
    }

    public function testPullMapsUserRatesToSyncItemsIncludingRewatching(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn([
            'userRates' => [
                ['anime' => ['id' => '20', 'name' => 'Naruto'], 'status' => 'watching'],
                ['anime' => ['id' => '21', 'name' => 'Bleach'], 'status' => 'rewatching'],
                ['anime' => ['id' => '22', 'name' => 'One Piece'], 'status' => 'dropped'],
            ],
        ]);

        $filler = $this->buildFiller($client, null, $this->realAuthRetrier('the-token'));

        $items = iterator_to_array($filler->pull());

        self::assertEquals([
            new SyncItem('20', SyncStatus::Watching, 'Naruto'),
            new SyncItem('21', SyncStatus::Watching, 'Bleach'),
            new SyncItem('22', SyncStatus::Dropped, 'One Piece'),
        ], $items);
    }

    public function testPullMapsUpdatedAtAndWatchedEpisodes(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn([
            'userRates' => [
                [
                    'anime' => ['id' => '20', 'name' => 'Naruto'],
                    'status' => 'watching',
                    'episodes' => 55,
                    'updatedAt' => '2026-08-10T09:30:00.000+03:00',
                ],
            ],
        ]);

        $filler = $this->buildFiller($client, null, $this->realAuthRetrier('the-token'));

        $items = iterator_to_array($filler->pull());

        self::assertEquals(
            [new SyncItem('20', SyncStatus::Watching, 'Naruto', new \DateTimeImmutable('2026-08-10T09:30:00.000+03:00'), 55)],
            $items,
        );
    }

    public function testPullPaginatesUntilAShortPage(): void
    {
        $fullPage = array_fill(0, 50, ['anime' => ['id' => '1', 'name' => 'Filler'], 'status' => 'planned']);
        $shortPage = [['anime' => ['id' => '2', 'name' => 'Last'], 'status' => 'completed']];

        $calls = 0;
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturnCallback(
            function () use (&$calls, $fullPage, $shortPage): array {
                ++$calls;

                return ['userRates' => $calls === 1 ? $fullPage : $shortPage];
            },
        );

        $filler = $this->buildFiller($client, null, $this->realAuthRetrier('the-token'));

        $items = iterator_to_array($filler->pull());

        self::assertCount(51, $items);
        self::assertSame(2, $calls);
    }

    public function testPullIsLazyAndOnlyFetchesPagesAsTheyAreConsumed(): void
    {
        $fullPage = array_fill(0, 50, ['anime' => ['id' => '1', 'name' => 'Filler'], 'status' => 'planned']);

        $calls = 0;
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturnCallback(
            function () use (&$calls, $fullPage): array {
                ++$calls;

                return ['userRates' => $fullPage];
            },
        );

        $filler = $this->buildFiller($client, null, $this->realAuthRetrier('the-token'));

        $generator = $filler->pull();
        self::assertSame(0, $calls, 'pull() must not issue any request before the caller starts iterating');

        $generator->current();
        self::assertSame(1, $calls, 'the first page is fetched once the caller asks for the first item');
    }

    private function realAuthRetrier(string $bearer): ShikimoriAuthRetrier
    {
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        $oauth->method('accessToken')->willReturn($bearer);
        $oauth->expects(self::never())->method('refreshAccessToken');

        return new ShikimoriAuthRetrier($oauth);
    }

    private function buildFiller(
        GraphQlClient $client,
        ?ShikimoriRestClient $restClient = null,
        ?ShikimoriAuthRetrier $authRetrier = null,
    ): ShikimoriFiller {
        return new ShikimoriFiller(
            $client,
            $restClient ?? $this->createMock(ShikimoriRestClient::class),
            $authRetrier ?? $this->createMock(ShikimoriAuthRetrier::class),
            $this->stubOwnManifest(),
        );
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
    private static function narutoFixture(): array
    {
        return [
            'id' => '20',
            'name' => 'Naruto',
            'russian' => 'Наруто',
            'english' => null,
            'japanese' => null,
            'synonyms' => [],
            'kind' => 'tv',
            'rating' => 'pg_13',
            'status' => 'released',
            'episodes' => 220,
            'duration' => 23,
            'score' => '7.91',
            'airedOn' => ['date' => '2002-10-03'],
            'releasedOn' => ['date' => '2007-02-08'],
            'poster' => [
                'originalUrl' => 'https://shikimori.io/system/animes/original/20.jpg',
                'mainUrl' => 'https://shikimori.io/system/animes/main/20.jpg',
            ],
            'studios' => [['id' => 1, 'name' => 'Studio Pierrot']],
            'genres' => [
                ['id' => 1, 'name' => 'Action', 'russian' => 'Экшен', 'kind' => 'genre'],
                ['id' => 2, 'name' => 'Award Winning', 'russian' => 'Награждённое', 'kind' => 'theme'],
                ['id' => 3, 'name' => 'Isekai', 'russian' => 'Исекай', 'kind' => 'theme'],
                ['id' => 4, 'name' => 'Shounen', 'russian' => 'Сёнэн', 'kind' => 'demographic'],
            ],
            'description' => '[character=7407]Девятихвостый лис[/character] напал на деревню.',
        ];
    }
}
