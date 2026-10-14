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

        self::assertNotNull($page);
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

    public function testNotConnectAnswerToCardIsNotFoundAndNotCached(): void
    {
        $this->queueFixture('search_not_connect_redirect.html');

        self::assertNull($this->client->fetchCard(9999999));
        self::assertSame([], glob($this->cacheDir.'/worldart-card-*'));

        $this->queueFixture('card_6.html');
        self::assertNotNull($this->client->fetchCard(9999999));
        self::assertCount(2, $this->requests);
    }

    public function testAnimationIndexRedirectIsNotFoundAndNotCached(): void
    {
        $this->queueFixture('card_animation_index_redirect.html');

        self::assertNull($this->client->fetchCard(11800));
        self::assertSame([], glob($this->cacheDir.'/worldart-card-*'));
        self::assertSame([], glob($this->cacheDir.'/tmp*') ?: []);
    }

    public function testSlashlessRefreshTargetsAreNotFound(): void
    {
        foreach (['animation/', '/animation', 'not_connect.html'] as $target) {
            $this->responses[] = $this->response(200, "<html><head><meta http-equiv='Refresh' content='0; url=".$target."'></head></html>");
            self::assertNull($this->client->fetchCard(5), $target);
        }
        self::assertSame([], glob($this->cacheDir.'/worldart-card-*'));
    }

    public function testFirstCardCoverUnderAnotherIdIsNotFoundButCardOneIsACard(): void
    {
        $this->queueFixture('card_1.html');
        self::assertNull($this->client->fetchCard(7));
        self::assertSame([], glob($this->cacheDir.'/worldart-card-*'));

        $this->queueFixture('card_1.html');
        $page = $this->client->fetchCard(1);
        self::assertNotNull($page);
        self::assertSame('Акира', $page->title());
        self::assertSame('http://www.world-art.ru/animation/img/1000/1/1.jpg', $page->coverSrc());
        self::assertFileExists($this->cardCachePath(1));
    }

    public function testAnswerWithoutTitleOrWithoutPairsIsNotACard(): void
    {
        $noTitle = "<html><body><table><tr><td class='review'><b>Тип</b></td><td></td><td class='review'>ТВ</td></tr></table></body></html>";
        $noPairs = '<html><body><font size=5>Название</font><p>Portal</p></body></html>';
        foreach ([$noTitle, $noPairs, '', '<html></html>'] as $body) {
            $this->responses[] = $this->response(200, $body);
            self::assertNull($this->client->fetchCard(5));
        }
        self::assertSame([], glob($this->cacheDir.'/worldart-card-*'));
        // the next request goes to the network again
        $this->queueFixture('card_6.html');
        self::assertNotNull($this->client->fetchCard(5));
        self::assertCount(5, $this->requests);
    }

    public function testCardIsCachedAsRawBytesAndServedWithoutRequest(): void
    {
        $this->queueFixture('card_6.html');

        $first = $this->client->fetchCard(6);
        self::assertNotNull($first);
        self::assertSame($this->fixture('card_6.html'), file_get_contents($this->cardCachePath(6)));
        touch($this->cardCachePath(6), (int) $this->now - 86400 + 60);
        $start = $this->now;

        $second = $this->client->fetchCard(6);

        self::assertNotNull($second);
        self::assertSame('Эксперименты Лэйн', $second->title());
        self::assertCount(1, $this->requests);
        // a cache hit does not pass the limiter
        self::assertSame($start, $this->now);
    }

    public function testExpiredCardIsRequestedAgain(): void
    {
        $this->queueFixture('card_6.html');
        $this->client->fetchCard(6);
        touch($this->cardCachePath(6), (int) $this->now - 86400);
        $this->queueFixture('card_6.html');

        $this->client->fetchCard(6);

        self::assertCount(2, $this->requests);
    }

    public function testCachedPageThatIsNotACardIsIgnored(): void
    {
        file_put_contents($this->cardCachePath(6), '<html>proxy</html>');
        touch($this->cardCachePath(6), (int) $this->now);
        $this->queueFixture('card_6.html');

        self::assertNotNull($this->client->fetchCard(6));
        self::assertCount(1, $this->requests);
    }

    public function testEveryCardFixtureHasTitleAndPairsWithoutGarbling(): void
    {
        $expected = [
            6 => ['card_6.html', 'Эксперименты Лэйн'],
            1 => ['card_1.html', 'Акира'],
            11466 => ['card_11466.html', 'Фрирен [ТВ-1]'],
            1500 => ['card_1500.html', 'Popee the Performer'],
            10000 => ['card_10000.html', 'Коколорс'],
            20 => ['card_movie_20.html', 'Медицинские карты Чёрного Джека (фильм первый)'],
            100 => ['card_ova_100.html', 'Голос далёкой звезды'],
            11000 => ['card_web_11000.html', 'Ух ты, египетские боги! 2'],
            8000 => ['card_tv_special_8000.html', 'Няруко! Помни мою любовь'],
        ];
        foreach ($expected as $id => [$file, $title]) {
            $this->queueFixture($file);
            $page = $this->client->fetchCard($id);
            self::assertNotNull($page, $file);
            self::assertSame($title, $page->title(), $file);
            self::assertNotSame([], $page->pairs(), $file);
        }
    }

    public function testCardTypePairsAreExtracted(): void
    {
        $this->queueFixture('card_tv_special_8000.html');
        $page = $this->client->fetchCard(8000);

        self::assertNotNull($page);
        self::assertContains(['label' => 'Тип', 'value' => 'ТВ (11 эп. + спэшл), 4 мин.'], $page->pairs());
    }

    public function testSearchIsNeverCached(): void
    {
        $this->queueFixture('search_naruto.html');
        $this->queueFixture('search_naruto.html');

        $this->client->search('naruto');
        $this->client->search('naruto');

        self::assertCount(2, $this->requests);
        self::assertSame([], glob($this->cacheDir.'/worldart-card-*'));
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
