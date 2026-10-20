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

namespace AnimeDb\Plugins\AnimedbWikidata\Tests\Http;

use AnimeDb\Plugins\AnimedbWikidata\Http\WikidataClient;
use AnimeDb\Plugins\AnimedbWikidata\Http\WikidataRequestException;
use AnimeDb\Plugins\AnimedbWikidata\Tests\Support\WikidataTestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class WikidataClientTest extends WikidataTestCase
{
    public function testSearchByImdbFindsTheItem(): void
    {
        $this->queueFixture('search_imdb_tt1568921.json');

        self::assertSame('Q699835', $this->client->findItemByProperty('P345', 'tt1568921'));
        self::assertStringStartsWith('https://www.wikidata.org/w/api.php?', $this->requests[0]);
        self::assertSame('haswbstatement:P345=tt1568921', $this->query(0)['srsearch']);
        self::assertSame('query', $this->query(0)['action']);
        self::assertSame('search', $this->query(0)['list']);
    }

    public function testSearchByKinopoiskFindsTheSameItem(): void
    {
        $this->queueFixture('search_kinopoisk_492366.json');

        self::assertSame('Q699835', $this->client->findItemByProperty('P2603', '492366'));
        self::assertSame('haswbstatement:P2603=492366', $this->query(0)['srsearch']);
    }

    public function testSearchWithoutResultsIsNull(): void
    {
        $this->queueFixture('search_empty.json');

        self::assertNull($this->client->findItemByProperty('P345', 'tt0000000000'));
    }

    public function testSearchWithSeveralItemsIsNull(): void
    {
        $this->queueFixture('search_multiple_synthetic.json');

        self::assertNull($this->client->findItemByProperty('P345', 'tt1568921'));
    }

    public function testFetchEntityAsksForClaimsAndLabels(): void
    {
        $this->queueFixture('entity_Q699835.json');

        $entity = $this->client->fetchEntity('Q699835');

        self::assertNotNull($entity);
        self::assertSame('Q699835', $entity['id']);
        self::assertSame('Arrietty', $entity['labels']['en']['value']);
        self::assertArrayHasKey('P345', $entity['claims']);
        self::assertSame('wbgetentities', $this->query(0)['action']);
        self::assertSame('Q699835', $this->query(0)['ids']);
        self::assertSame('claims|labels', $this->query(0)['props']);
        self::assertSame('ja|en|ru', $this->query(0)['languages']);
    }

    public function testFetchSeriesEntityHasParts(): void
    {
        $this->queueFixture('entity_Q22126305.json');

        $entity = $this->client->fetchEntity('Q22126305');

        self::assertNotNull($entity);
        self::assertArrayHasKey('P527', $entity['claims']);
    }

    public function testMissingEntityIsNull(): void
    {
        $this->responses[] = $this->response(200, '{"entities":{"Q999999999":{"id":"Q999999999","missing":""}}}');

        self::assertNull($this->client->fetchEntity('Q999999999'));
    }

    public function testEveryRequestCarriesMaxlagFormatAndUserAgent(): void
    {
        $this->queueFixture('search_imdb_tt1568921.json');
        $this->queueFixture('entity_Q699835.json');

        $this->client->findItemByProperty('P345', 'tt1568921');
        $this->client->fetchEntity('Q699835');

        foreach ([0, 1] as $index) {
            self::assertStringStartsWith('https://', $this->requests[$index]);
            self::assertSame('5', $this->query($index)['maxlag']);
            self::assertSame('json', $this->query($index)['format']);
            $agent = $this->requestHeaders[$index]['User-Agent'];
            self::assertStringContainsString('testvendor-probe', $agent);
            self::assertStringContainsString('9.8.7', $agent);
            self::assertStringContainsString('https://github.com/anime-db/anime-db-plugins', $agent);
        }
    }

    public function testMaxlagAnswerIsAnException(): void
    {
        $this->responses[] = $this->response(200, '{"error":{"code":"maxlag","info":"Waiting for a database server: 6 seconds lagged.","lag":6},"servedby":"mw"}');

        try {
            $this->client->findItemByProperty('P345', 'tt1568921');
            self::fail('Expected an exception.');
        } catch (WikidataRequestException $exception) {
            self::assertStringContainsString('maxlag', $exception->getMessage());
        }
        self::assertCount(1, $this->requests, 'no retries');
    }

    public function testNetworkFailureIsAnException(): void
    {
        $this->responses[] = new class('down') extends \RuntimeException implements ClientExceptionInterface {
        };

        $this->expectException(WikidataRequestException::class);
        $this->client->fetchEntity('Q699835');
    }

    public function testServerErrorIsAnException(): void
    {
        $this->responses[] = $this->response(503, 'unavailable');

        $this->expectException(WikidataRequestException::class);
        $this->client->fetchEntity('Q699835');
    }

    public function testBrokenJsonIsAnException(): void
    {
        $this->responses[] = $this->response(200, '{"query":');

        $this->expectException(WikidataRequestException::class);
        $this->client->findItemByProperty('P345', 'tt1568921');
    }

    public function testResponseWithoutSearchListIsAnException(): void
    {
        $this->responses[] = $this->response(200, '{"batchcomplete":""}');

        $this->expectException(WikidataRequestException::class);
        $this->client->findItemByProperty('P345', 'tt1568921');
    }

    public function testBadArgumentsNeverReachTheNetwork(): void
    {
        foreach ([
            fn () => $this->client->findItemByProperty('P345', 'tt1 OR haswbstatement:P1=2'),
            fn () => $this->client->findItemByProperty('345', 'tt1'),
            fn () => $this->client->fetchEntity('Q1|Q2'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected an exception.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->requests);
    }

    public function testRequestsAreSpacedByOneSecondThroughTheLockFile(): void
    {
        $this->queueFixture('search_empty.json');
        $this->queueFixture('search_empty.json');
        $this->queueFixture('search_empty.json');
        $start = $this->now;

        $this->client->findItemByProperty('P345', 'tt1');
        $this->client->findItemByProperty('P345', 'tt2');
        $this->client->findItemByProperty('P345', 'tt3');

        self::assertEqualsWithDelta(2.0, $this->now - $start, 0.001);
        self::assertFileExists($this->cacheDir.'/wikidata.lock');
    }
}
