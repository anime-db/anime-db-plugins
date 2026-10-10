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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\Http;

use AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface;
use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\Plugins\AnimedbAnidb\Dump\FileLock;
use AnimeDb\Plugins\AnimedbAnidb\Http\AniDbApiClient;
use AnimeDb\Plugins\AnimedbAnidb\Http\AniDbRequestException;
use AnimeDb\Plugins\AnimedbAnidb\Http\BanGuard;
use AnimeDb\Plugins\AnimedbAnidb\Http\CardCache;
use AnimeDb\Plugins\AnimedbAnidb\Http\HotAnimeCache;
use AnimeDb\Plugins\AnimedbAnidb\Http\NotFoundHttpException;
use AnimeDb\Plugins\AnimedbAnidb\Http\RequestLimiter;
use AnimeDb\Plugins\AnimedbAnidb\Tests\Support\AnidbTestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class AniDbApiClientTest extends AnidbTestCase
{
    private AniDbApiClient $client;
    private CardCache $cardCache;

    /** @var list<float> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sleeps = [];
        // cache entries get a real mtime, so the movable clock starts at the real time
        $this->now = time();

        $directory = $this->createMock(PluginCacheDirectoryInterface::class);
        $directory->method('path')->willReturn($this->cacheDir);
        $manifest = $this->createMock(OwnManifestInterface::class);
        $manifest->method('id')->willReturn('animedb-anidb');
        $manifest->method('version')->willReturn('0.1.1');

        $this->cardCache = new CardCache($directory, fn (): int => $this->now);
        $this->client = new AniDbApiClient(
            $this->httpClient(),
            $this->requestFactory(),
            $manifest,
            new RequestLimiter(
                $directory,
                new FileLock(),
                fn (): float => (float) $this->now,
                function (float $seconds): void {
                    $this->sleeps[] = $seconds;
                    $this->now += (int) ceil($seconds);
                },
            ),
            new BanGuard($this->settings, $directory, fn (): int => $this->now),
            $this->cardCache,
            new HotAnimeCache($directory, fn (): int => $this->now),
        );
    }

    public function testRequestCarriesTheClientIdentityAndUserAgent(): void
    {
        $this->responses = [$this->xml(self::fixture('card_7000.xml'))];

        $card = $this->client->fetchAnime(7000);

        self::assertSame('7000', (string) $card['id']);
        self::assertCount(1, $this->requests);
        self::assertSame(
            'http://api.anidb.net:9001/httpapi?request=anime&client=animedbplugin&clientver=1&protover=1&aid=7000',
            $this->requests[0]['url'],
        );
        self::assertSame('AnimeDB animedb-anidb/0.1.1 (+https://anime-db.org/)', $this->requests[0]['headers']['User-Agent']);
    }

    public function testIdentityConstantsAreDeclaredInOnePlace(): void
    {
        self::assertSame('http://api.anidb.net:9001/httpapi', AniDbApiClient::BASE_URL);
        self::assertSame('animedbplugin', AniDbApiClient::CLIENT);
        self::assertSame(1, AniDbApiClient::CLIENT_VERSION);
        self::assertSame(1, AniDbApiClient::PROTOCOL_VERSION);
    }

    public function testEmptyCacheDirectoryWorks(): void
    {
        self::assertSame([], glob($this->cacheDir.'/*'));
        $this->responses = [$this->xml(self::fixture('card_7000.xml'))];

        $this->client->fetchAnime(7000);

        self::assertCount(1, $this->requests);
    }

    public function testCardIsCachedUnpackedAndServedWithoutARequest(): void
    {
        $this->responses = [$this->gz(self::fixture('card_7000.xml'))];
        $this->client->fetchAnime(7000);

        self::assertSame(self::fixture('card_7000.xml'), file_get_contents($this->cardCache->path(7000)));

        $card = $this->client->fetchAnime(7000);

        self::assertSame('7000', (string) $card['id']);
        self::assertCount(1, $this->requests);
    }

    public function testCardOlderThanADayIsRequestedAgain(): void
    {
        $this->responses = [$this->xml(self::fixture('card_7000.xml')), $this->xml(self::fixture('card_7000.xml'))];
        $this->client->fetchAnime(7000);
        touch($this->cardCache->path(7000), $this->now - 86400);

        $this->client->fetchAnime(7000);

        self::assertCount(2, $this->requests);
    }

    public function testGzipIsDetectedByMagicBytesRegardlessOfHeaders(): void
    {
        $this->responses = [$this->response(200, (string) gzencode(self::fixture('card_2500.xml')), ['Content-Encoding' => 'identity'])];

        $card = $this->client->fetchAnime(2500);

        self::assertSame('true', (string) $card['restricted']);
    }

    public function testPlainBodyIsParsedAsIs(): void
    {
        $this->responses = [$this->response(200, self::fixture('card_13000.xml'), ['Content-Encoding' => 'gzip'])];

        $card = $this->client->fetchAnime(13000);

        self::assertSame('Music Video', (string) $card->type);
    }

    public function testCorruptGzipFails(): void
    {
        $this->responses = [$this->response(200, "\x1f\x8bnot gzip")];

        $this->expectException(AniDbRequestException::class);
        $this->client->fetchAnime(1);
    }

    public function testExternalEntitiesAreNotSubstituted(): void
    {
        $secret = $this->cacheDir.'/secret.txt';
        file_put_contents($secret, 'TOP-SECRET');
        $xml = '<?xml version="1.0"?><!DOCTYPE anime [<!ENTITY x SYSTEM "file://'.$secret.'">]><anime id="1"><type>&x;</type></anime>';
        $this->responses = [$this->xml($xml)];

        try {
            $card = $this->client->fetchAnime(1);
            self::assertStringNotContainsString('TOP-SECRET', (string) $card->type);
        } catch (AniDbRequestException) {
            $this->addToAssertionCount(1);
        } finally {
            unlink($secret);
        }
    }

    public function testNotFoundFixtureThrowsNotFound(): void
    {
        $this->responses = [$this->gz(self::fixture('not_found.xml'))];

        $this->expectException(NotFoundHttpException::class);
        $this->client->fetchAnime(5000);
    }

    public function testUnavailableCacheDirectoryFailsWithRequestException(): void
    {
        $directory = $this->createMock(PluginCacheDirectoryInterface::class);
        $directory->method('path')->willThrowException(new \RuntimeException('no dir'));
        $manifest = $this->createMock(OwnManifestInterface::class);
        $manifest->method('id')->willReturn('animedb-anidb');
        $manifest->method('version')->willReturn('0.1.1');
        $client = new AniDbApiClient(
            $this->httpClient(),
            $this->requestFactory(),
            $manifest,
            new RequestLimiter($directory, new FileLock(), fn (): float => (float) $this->now, static function (float $seconds): void {
            }),
            new BanGuard($this->settings, $directory, fn (): int => $this->now),
            new CardCache($directory, fn (): int => $this->now),
            new HotAnimeCache($directory, fn (): int => $this->now),
        );
        $this->responses = [$this->xml(self::fixture('card_7000.xml'))];

        $this->expectException(AniDbRequestException::class);
        $client->fetchAnime(7000);
    }

    public function testNotFoundIsNotCached(): void
    {
        $this->responses = [$this->xml(self::fixture('not_found.xml'))];
        try {
            $this->client->fetchAnime(5000);
        } catch (NotFoundHttpException) {
        }

        self::assertFileDoesNotExist($this->cardCache->path(5000));
    }

    public function testUnknownClientFixtureThrowsRequestException(): void
    {
        $this->responses = [$this->xml(self::fixture('unknown_client.xml'))];

        $this->expectException(AniDbRequestException::class);
        $this->client->fetchAnime(1);
    }

    public function testBannedResponseSetsTheBanWindowAndBlocksNextCalls(): void
    {
        $this->responses = [$this->xml('<error>Banned</error>')];
        try {
            $this->client->fetchAnime(1);
            self::fail('Expected AniDbRequestException.');
        } catch (AniDbRequestException) {
        }

        self::assertSame($this->now + 86400, $this->settings->data['api_banned_until']);

        // before the moment: no HTTP request at all
        try {
            $this->client->fetchAnime(2);
            self::fail('Expected AniDbRequestException.');
        } catch (AniDbRequestException) {
        }
        self::assertCount(1, $this->requests);

        // after it: the request goes out
        $this->now += 86400;
        $this->responses = [$this->xml(self::fixture('card_7000.xml'))];
        $this->client->fetchAnime(7000);
        self::assertCount(2, $this->requests);
    }

    public function testBanDoesNotBlockCachedCards(): void
    {
        $this->responses = [$this->xml(self::fixture('card_7000.xml'))];
        $this->client->fetchAnime(7000);
        $this->settings->data['api_banned_until'] = $this->now + 100;

        $this->client->fetchAnime(7000);

        self::assertCount(1, $this->requests);
    }

    public function testTwoRequestsAreSpacedByTheLimiter(): void
    {
        $this->responses = [$this->xml(self::fixture('card_7000.xml')), $this->xml(self::fixture('card_13000.xml'))];

        $this->client->fetchAnime(7000);
        $this->client->fetchAnime(13000);

        self::assertEqualsWithDelta(2.0, array_sum($this->sleeps), 0.001);
    }

    public function testHeartbeatIsPassedToTheLimiter(): void
    {
        $this->responses = [$this->xml(self::fixture('card_7000.xml')), $this->xml(self::fixture('card_13000.xml'))];
        $this->client->fetchAnime(7000);

        $beats = 0;
        $this->client->fetchAnime(13000, static function () use (&$beats): void {
            ++$beats;
        });

        self::assertGreaterThan(0, $beats);
    }

    public function testOverlongQueueFailsWithoutAnHttpRequest(): void
    {
        file_put_contents($this->cacheDir.'/anidb-api.slot', sprintf('%.6F', $this->now + 21.0));

        try {
            $this->client->fetchAnime(7000);
            self::fail('Expected AniDbRequestException.');
        } catch (AniDbRequestException) {
        }

        self::assertSame([], $this->requests);
    }

    public function testNon2xxFails(): void
    {
        $this->responses = [$this->response(503, 'down')];

        $this->expectException(AniDbRequestException::class);
        $this->client->fetchAnime(1);
    }

    public function testTransportFailureFails(): void
    {
        $this->responses = [new class('boom') extends \RuntimeException implements ClientExceptionInterface {}];

        $this->expectException(AniDbRequestException::class);
        $this->client->fetchAnime(1);
    }

    public function testInvalidXmlFails(): void
    {
        $this->responses = [$this->xml('<anime')];

        $this->expectException(AniDbRequestException::class);
        $this->client->fetchAnime(1);
    }

    public function testUnexpectedRootFails(): void
    {
        $this->responses = [$this->xml('<html/>')];

        $this->expectException(AniDbRequestException::class);
        $this->client->fetchAnime(1);
    }

    /**
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function xml(string $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->response(200, $body);
    }

    private function gz(string $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->response(200, (string) gzencode($body));
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixture/'.$name);
    }
}
