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
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient;
use AnimeDb\Plugins\AnimedbMyanimelist\MalFiller;
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
        $client->method('get')->willReturn(self::cardFixture('card_3455.json'));

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

    private function buildFiller(MalApiClient $client): MalFiller
    {
        return new MalFiller($client, $this->stubOwnManifest());
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
