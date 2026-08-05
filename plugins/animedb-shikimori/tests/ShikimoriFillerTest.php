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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests;

use AnimeDb\PluginContracts\Model\AnimeType;
use AnimeDb\PluginContracts\Model\Demographic;
use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\ThemeCode;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlClient;
use AnimeDb\Plugins\AnimedbShikimori\ShikimoriFiller;
use PHPUnit\Framework\TestCase;

final class ShikimoriFillerTest extends TestCase
{
    public function testFindMapsCandidates(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn([
            'animes' => [
                ['id' => '20', 'name' => 'Naruto', 'russian' => 'Наруто', 'kind' => 'tv'],
                ['id' => '813', 'name' => 'Dragon Ball Z', 'russian' => null, 'kind' => 'tv'],
            ],
        ]);

        $filler = new ShikimoriFiller($client);
        $candidates = $filler->find('naruto');

        self::assertEquals([
            new SearchByPluginCandidate('animedb-shikimori', 'Naruto', '20'),
            new SearchByPluginCandidate('animedb-shikimori', 'Dragon Ball Z', '813'),
        ], $candidates);
    }

    public function testFindReturnsEmptyArrayWhenNothingMatches(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => []]);

        $filler = new ShikimoriFiller($client);

        self::assertSame([], $filler->find('does not exist'));
    }

    public function testFindByIdReturnsNullWhenAnimeIsUnknown(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => []]);

        $filler = new ShikimoriFiller($client);

        self::assertNull($filler->findById('999999999'));
    }

    public function testFindByIdMapsFullCard(): void
    {
        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => [self::narutoFixture()]]);

        $filler = new ShikimoriFiller($client);
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

        $filler = new ShikimoriFiller($client);
        $data = $filler->findById('20');

        self::assertSame(['NARUTO -ナルト-'], $data->alternativeNames);
    }

    public function testFindByIdMapsTvSpecialToSpecial(): void
    {
        $fixture = self::narutoFixture();
        $fixture['kind'] = 'tv_special';

        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => [$fixture]]);

        $filler = new ShikimoriFiller($client);

        self::assertSame(AnimeType::Special, $filler->findById('20')->type);
    }

    public function testFindByIdDropsTypeForPromotionalVideoKind(): void
    {
        $fixture = self::narutoFixture();
        $fixture['kind'] = 'pv';

        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => [$fixture]]);

        $filler = new ShikimoriFiller($client);

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

        $filler = new ShikimoriFiller($client);

        self::assertSame(Demographic::Seinen, $filler->findById('20')->demographic);
    }

    public function testFindByIdLeavesDatesNullWhenMissingOrIncomplete(): void
    {
        $fixture = self::narutoFixture();
        $fixture['airedOn'] = null;
        $fixture['releasedOn'] = ['date' => '2007-00-00'];

        $client = $this->createMock(GraphQlClient::class);
        $client->method('query')->willReturn(['animes' => [$fixture]]);

        $filler = new ShikimoriFiller($client);
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

        $filler = new ShikimoriFiller($client);

        self::assertNull($filler->findById('20')->episodesCount);
    }

    public function testGetFillableFieldsExcludesCountriesAndImages(): void
    {
        $filler = new ShikimoriFiller($this->createMock(GraphQlClient::class));

        $fields = $filler->getFillableFields();

        self::assertNotContains('countries', $fields);
        self::assertNotContains('images', $fields);
        self::assertContains('title', $fields);
        self::assertContains('genres', $fields);
        self::assertContains('demographic', $fields);
    }

    public function testResolveExternalIdMatchesShikimoriDomainsAndPaths(): void
    {
        $filler = new ShikimoriFiller($this->createMock(GraphQlClient::class));

        self::assertSame('20', $filler->resolveExternalId(['https://shikimori.io/animes/20-naruto']));
        self::assertSame('20', $filler->resolveExternalId(['https://shikimori.one/animes/z20-naruto']));
        self::assertNull($filler->resolveExternalId(['https://myanimelist.net/anime/20']));
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
