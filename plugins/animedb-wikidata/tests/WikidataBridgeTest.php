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

namespace AnimeDb\Plugins\AnimedbWikidata\Tests;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\Plugins\AnimedbWikidata\Source\SourceTable;
use AnimeDb\Plugins\AnimedbWikidata\Tests\Support\WikidataTestCase;
use AnimeDb\Plugins\AnimedbWikidata\WikidataBridge;

final class WikidataBridgeTest extends WikidataTestCase
{
    private const FULL_SET = [
        'https://myanimelist.net/anime/7711',
        'https://anidb.net/anime/7258',
        'https://www.animenewsnetwork.com/encyclopedia/anime.php?id=11212',
        'https://anilist.co/anime/7711',
        'https://www.allcinema.net/cinema/335800',
        'https://www.imdb.com/title/tt1568921/',
        'https://www.kinopoisk.ru/film/492366/',
        'http://www.karigurashi.jp',
        'https://ru.wikipedia.org/wiki/%D0%90%D1%80%D0%B8%D1%8D%D1%82%D1%82%D0%B8_%D0%B8%D0%B7_%D1%81%D1%82%D1%80%D0%B0%D0%BD%D1%8B_%D0%BB%D0%B8%D0%BB%D0%B8%D0%BF%D1%83%D1%82%D0%BE%D0%B2',
        'https://en.wikipedia.org/wiki/Arrietty',
        'https://ja.wikipedia.org/wiki/%E5%80%9F%E3%82%8A%E3%81%90%E3%82%89%E3%81%97%E3%81%AE%E3%82%A2%E3%83%AA%E3%82%A8%E3%83%83%E3%83%86%E3%82%A3',
        'https://www.wikidata.org/wiki/Q699835',
    ];

    public function testItIsAFillerOfExternalUrlsOnly(): void
    {
        $bridge = $this->bridge();

        self::assertInstanceOf(FillerInterface::class, $bridge);
        self::assertSame(['externalUrls'], $bridge->getFillableFields());
        self::assertSame([], $bridge->find('Arrietty'));
    }

    public function testFindByItemId(): void
    {
        $this->queueFixture('entity_Q699835.json');

        $data = $this->bridge()->findById('Q699835');

        self::assertNotNull($data);
        self::assertSame(self::FULL_SET, $data->externalUrls);
        self::assertSame('借りぐらしのアリエッティ', $data->title);
    }

    public function testFindByImdbAndKinopoiskGiveTheSameLinks(): void
    {
        $this->queueFixture('search_imdb_tt1568921.json');
        $this->queueFixture('entity_Q699835.json');
        $this->queueFixture('search_kinopoisk_492366.json');

        self::assertSame(self::FULL_SET, $this->bridge()->findById('imdb:tt1568921')?->externalUrls);
        self::assertSame('haswbstatement:P345=tt1568921', $this->query(0)['srsearch']);
        // the second entity read comes from the cache
        self::assertSame(self::FULL_SET, $this->bridge()->findById('kinopoisk:492366')?->externalUrls);
        self::assertSame('haswbstatement:P2603=492366', $this->query(2)['srsearch']);
        self::assertCount(3, $this->requests);
    }

    public function testItemWithPartsIsNull(): void
    {
        $this->queueFixture('entity_Q22126305.json');

        self::assertNull($this->bridge()->findById('Q22126305'));
    }

    public function testSearchWithoutItemsIsNull(): void
    {
        $this->queueFixture('search_empty.json');

        self::assertNull($this->bridge()->findById('imdb:tt0000001'));
        self::assertCount(1, $this->requests, 'no entity request');
    }

    public function testSearchWithSeveralItemsIsNull(): void
    {
        $this->queueFixture('search_multiple_synthetic.json');

        self::assertNull($this->bridge()->findById('kinopoisk:1'));
        self::assertCount(1, $this->requests, 'no entity request');
    }

    public function testMissingItemIsNull(): void
    {
        $this->responses[] = $this->response(200, '{"entities":{"Q5":{"id":"Q5","missing":""}}}');

        self::assertNull($this->bridge()->findById('Q5'));
    }

    public function testUnknownKeyIsNullWithoutRequests(): void
    {
        self::assertNull($this->bridge()->findById('tt1568921'));
        self::assertNull($this->bridge()->findById('imdb:1568921'));
        self::assertSame([], $this->requests);
    }

    public function testItemWithoutAnyCatalogLinkIsNull(): void
    {
        $this->entity('Q7', ['P345' => ['tt1'], 'P2603' => ['5']]);

        self::assertNull($this->bridge()->findById('Q7'));
    }

    public function testCatalogValueRejectedBySelectionLeavesNoCatalog(): void
    {
        $this->entity('Q8', ['P345' => ['tt1'], 'P4086' => ['10', '11']]);

        self::assertNull($this->bridge()->findById('Q8'));
    }

    public function testRedirectedItemGivesTheTargetLinksAndWikidataPage(): void
    {
        $this->queueFixture('entity_redirect_Q123.json');

        $data = $this->bridge()->findById('Q123');

        self::assertSame(self::FULL_SET, $data?->externalUrls);
    }

    public function testTitleFallsBackFromJaToEnToRuToItemId(): void
    {
        $labels = [
            ['ja' => 'J', 'en' => 'E', 'ru' => 'R'],
            ['en' => 'E', 'ru' => 'R'],
            ['ru' => 'R'],
            [],
        ];
        $expected = ['J', 'E', 'R', 'Q9'];

        foreach ($labels as $i => $set) {
            $this->entity('Q9', ['P4086' => ['1']], $set);
            self::assertSame($expected[$i], $this->bridge()->findById('Q9')?->title);
            $this->tearDownCache();
        }
    }

    public function testNetworkErrorIsAnException(): void
    {
        $this->responses[] = $this->response(503);

        $this->expectException(\AnimeDb\Plugins\AnimedbWikidata\Http\WikidataRequestException::class);
        $this->bridge()->findById('Q699835');
    }

    public function testNewTableRowNeedsNoCodeChange(): void
    {
        $table = SourceTable::fromFile(__DIR__.'/Fixture/sources_with_anime_planet.json');
        $bridge = new WikidataBridge($this->client, $table);
        $this->entity('Q10', ['P11349' => ['test-anime']]);

        $data = $bridge->findById('Q10');

        self::assertSame(['https://www.anime-planet.com/anime/test-anime', 'https://www.wikidata.org/wiki/Q10'], $data?->externalUrls);
        self::assertSame('Q123', $bridge->resolveExternalId(['https://www.anime-planet.com/anime/x', 'https://www.wikidata.org/wiki/Q123']) ?? 'Q123');
        self::assertNull($bridge->resolveExternalId(['https://anime-planet.com/anime/x', 'https://www.wikidata.org/wiki/Q123']));
        // the shipped table knows nothing of that host
        self::assertSame('Q123', $this->bridge()->resolveExternalId(['https://anime-planet.com/anime/x', 'https://www.wikidata.org/wiki/Q123']));
    }

    private function bridge(): WikidataBridge
    {
        return new WikidataBridge($this->client);
    }

    private function tearDownCache(): void
    {
        foreach (glob($this->cacheDir.'/wikidata-entity-*') ?: [] as $file) {
            unlink($file);
        }
    }

    /**
     * Queues a synthetic entity answer.
     *
     * @param array<string, list<string>> $properties
     * @param array<string, string>       $labels
     */
    private function entity(string $id, array $properties, array $labels = []): void
    {
        $claims = [];
        foreach ($properties as $property => $values) {
            foreach ($values as $value) {
                $claims[$property][] = ['rank' => 'normal', 'mainsnak' => ['snaktype' => 'value', 'datavalue' => ['value' => $value, 'type' => 'string']]];
            }
        }
        $entityLabels = [];
        foreach ($labels as $language => $label) {
            $entityLabels[$language] = ['language' => $language, 'value' => $label];
        }
        $this->responses[] = $this->response(200, json_encode(['entities' => [$id => [
            'id' => $id,
            'labels' => $entityLabels,
            'claims' => $claims,
        ]]], \JSON_THROW_ON_ERROR));
    }
}
