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

namespace AnimeDb\Plugins\AnimedbWorldart\Tests\Http;

use AnimeDb\Plugins\AnimedbWorldart\Http\WorldartClient;
use AnimeDb\Plugins\AnimedbWorldart\Http\WorldartRequestException;
use AnimeDb\Plugins\AnimedbWorldart\Tests\Support\WorldartTestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class WorldartClientTest extends WorldartTestCase
{
    public function testCardTitleFromRawWindows1251BytesIsNotGarbled(): void
    {
        $this->queueFixture('card_6.html');

        $page = $this->client->fetchCard(6);

        self::assertSame('Эксперименты Лэйн', $page->title());
        self::assertSame(['http://www.world-art.ru/animation/animation.php?id=6'], $this->requests);
        self::assertSame(
            'AnimeDB testvendor-probe/9.8.7 (+https://anime-db.org/)',
            $this->requestHeaders[0]['User-Agent'],
        );
    }

    public function testEmptyCacheDirectoryWorks(): void
    {
        self::assertSame([], glob($this->cacheDir.'/*'));
        $this->queueFixture('card_6.html');

        $this->client->fetchCard(6);

        self::assertSame(1, \count($this->requests));
    }

    public function testCardIsNeverCached(): void
    {
        $this->queueFixture('card_6.html');
        $this->queueFixture('card_6.html');

        $this->client->fetchCard(6);
        $this->client->fetchCard(6);

        self::assertCount(2, $this->requests);
    }

    public function testRequestsAreSpacedByTheLimiter(): void
    {
        $this->queueFixture('card_6.html');
        $this->queueFixture('card_6.html');
        $start = $this->now;

        $this->client->fetchCard(6);
        $this->client->fetchCard(6);

        self::assertEqualsWithDelta(2.0, $this->now - $start, 0.001);
    }

    public function testSearchRequestIsSentToHttpHostInWindows1251(): void
    {
        $this->queueFixture('search_naruto.html');

        $this->client->search(iconv('UTF-8', 'CP1251', 'Наруто'));

        self::assertSame(
            ['http://www.world-art.ru/search.php?public_search=%CD%E0%F0%F3%F2%EE&global_sector=animation'],
            $this->requests,
        );
    }

    public function testSearchGoesThroughTheLimiterToo(): void
    {
        $this->queueFixture('search_naruto.html');
        $this->queueFixture('search_naruto.html');
        $start = $this->now;

        $this->client->search('naruto');
        $this->client->search('naruto');

        self::assertEqualsWithDelta(2.0, $this->now - $start, 0.001);
    }

    public function testTransportFailureIsARequestException(): void
    {
        $this->responses[] = new class('boom') extends \RuntimeException implements ClientExceptionInterface {
        };

        $this->expectException(WorldartRequestException::class);
        $this->client->fetchCard(6);
    }

    public function testNon200StatusIsARequestException(): void
    {
        foreach ([301, 404, 500, 503] as $status) {
            $this->responses[] = $this->response($status, 'x');
            try {
                $this->client->fetchCard(6);
                self::fail('Expected WorldartRequestException for '.$status);
            } catch (WorldartRequestException) {
            }
        }
    }

    public function testNotConnectAnswerToSearchIsARequestException(): void
    {
        $this->queueFixture('search_not_connect_redirect.html');

        $this->expectException(WorldartRequestException::class);
        $this->client->search('naruto');
    }

    public function testNotConnectAnswerToCardIsAPageWithoutTitle(): void
    {
        $this->queueFixture('search_not_connect_redirect.html');

        self::assertNull($this->client->fetchCard(9999999)->title());
    }

    public function testBaseUrlIsPlainHttp(): void
    {
        self::assertSame('http://www.world-art.ru', WorldartClient::BASE_URL);
    }

    public function testInvalidIdIsRejectedWithoutRequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->client->fetchCard(0);
        } finally {
            self::assertSame([], $this->requests);
        }
    }
}
