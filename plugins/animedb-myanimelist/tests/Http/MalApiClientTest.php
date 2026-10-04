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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Tests\Http;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\MalRequestException;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\NotFoundHttpException;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\RateLimiter;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\UnauthorizedHttpException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class MalApiClientTest extends TestCase
{
    private const STUB_MANIFEST_ID = 'animedb-myanimelist-stub';
    private const STUB_MANIFEST_VERSION = '9.9.9';

    public function testGetReturnsDecodedJsonBodyOnSuccess(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, ['data' => []]));

        $client = $this->buildClient($httpClient);

        self::assertSame(['data' => []], $client->get('/anime', ['q' => 'naruto']));
    }

    public function testTransportFailureThrowsMalRequestException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willThrowException($this->createMock(ClientExceptionInterface::class));

        $client = $this->buildClient($httpClient);

        $this->expectException(MalRequestException::class);
        $client->get('/anime', ['q' => 'naruto']);
    }

    public function testNonSuccessHttpStatusThrowsMalRequestException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(500, []));

        $client = $this->buildClient($httpClient);

        $this->expectException(MalRequestException::class);
        $client->get('/anime', ['q' => 'naruto']);
    }

    public function testInvalidJsonBodyThrowsMalRequestException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->rawResponse(200, 'not json'));

        $client = $this->buildClient($httpClient);

        $this->expectException(MalRequestException::class);
        $client->get('/anime', ['q' => 'naruto']);
    }

    public function test401ResponseThrowsUnauthorizedHttpException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(401, []));

        $client = $this->buildClient($httpClient);

        $this->expectException(UnauthorizedHttpException::class);
        $client->get('/anime', ['q' => 'naruto'], null, 'a-bearer-token');
    }

    public function test404ResponseThrowsNotFoundHttpException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(404, []));

        $client = $this->buildClient($httpClient);

        $this->expectException(NotFoundHttpException::class);
        $client->get('/anime/999999999', []);
    }

    public function testRetriesAfter429ThenSucceeds(): void
    {
        $responses = [
            $this->jsonResponse(429, [], ['Retry-After' => '1']),
            $this->jsonResponse(200, ['data' => []]),
        ];
        $call = 0;

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::exactly(2))
            ->method('sendRequest')
            ->willReturnCallback(static function () use (&$call, $responses): ResponseInterface {
                return $responses[$call++];
            });

        $client = $this->buildClient($httpClient);

        self::assertSame(['data' => []], $client->get('/anime', ['q' => 'naruto']));
    }

    public function testExhaustingTheRetryBudgetOn429ThrowsMalRequestException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::exactly(6))
            ->method('sendRequest')
            ->willReturn($this->jsonResponse(429, [], ['Retry-After' => '1']));

        $client = $this->buildClient($httpClient);

        $this->expectException(MalRequestException::class);
        $client->get('/anime', ['q' => 'naruto']);
    }

    public function testHeartbeatIsCalledOnEveryRetryAttempt(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(429, [], ['Retry-After' => '1']));

        $client = $this->buildClient($httpClient);

        $heartbeats = 0;
        try {
            $client->get('/anime', ['q' => 'naruto'], static function () use (&$heartbeats): void {
                ++$heartbeats;
            });
        } catch (MalRequestException) {
            // budget exhaustion is expected here — only the heartbeat count matters
        }

        self::assertGreaterThan(1, $heartbeats);
    }

    public function testAnonymousRequestCarriesClientIdHeaderButNoAuthorizationHeader(): void
    {
        $headers = [];
        $request = $this->fluentRequestCapturingHeadersInto($headers);

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::once())
            ->method('createRequest')
            ->with('GET', 'https://api.myanimelist.net/v2/anime?q=naruto')
            ->willReturn($request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, ['data' => []]));

        $client = $this->buildClient($httpClient, $requestFactory);
        $client->get('/anime', ['q' => 'naruto']);

        self::assertSame(MalApiClient::CLIENT_ID, $headers['X-MAL-CLIENT-ID'] ?? null);
        self::assertArrayNotHasKey('Authorization', $headers);
    }

    public function testBearerIsSentAsAuthorizationHeaderInsteadOfClientIdWhenGiven(): void
    {
        $headers = [];
        $request = $this->fluentRequestCapturingHeadersInto($headers);

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, ['data' => []]));

        $client = $this->buildClient($httpClient, $requestFactory);
        $client->get('/anime', ['q' => 'naruto'], null, 'the-bearer-token');

        self::assertSame('Bearer the-bearer-token', $headers['Authorization'] ?? null);
        self::assertArrayNotHasKey('X-MAL-CLIENT-ID', $headers);
    }

    public function testSendsUserAgentHeaderBuiltFromOwnManifest(): void
    {
        $headers = [];
        $request = $this->fluentRequestCapturingHeadersInto($headers);

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, ['data' => []]));

        $client = $this->buildClient($httpClient, $requestFactory);
        $client->get('/anime', ['q' => 'naruto']);

        self::assertSame(
            \sprintf('AnimeDB %s/%s (+https://anime-db.org/)', self::STUB_MANIFEST_ID, self::STUB_MANIFEST_VERSION),
            $headers['User-Agent'] ?? null,
        );
    }

    public function testFetchAnimeListPageRequestsNsfwFieldsOffsetAndLimit(): void
    {
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::once())
            ->method('createRequest')
            ->with(
                'GET',
                'https://api.myanimelist.net/v2/users/@me/animelist?fields=list_status&limit=5&offset=10&nsfw=true',
            )
            ->willReturn($this->fluentRequest());

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, ['data' => []]));

        $client = $this->buildClient($httpClient, $requestFactory);
        $client->fetchAnimeListPage('a-bearer-token', 10, 5);
    }

    public function testFetchAnimeListPageSendsBearerAuthorizationHeaderAndNoClientIdHeader(): void
    {
        $headers = [];
        $request = $this->fluentRequestCapturingHeadersInto($headers);

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, ['data' => []]));

        $client = $this->buildClient($httpClient, $requestFactory);
        $client->fetchAnimeListPage('a-bearer-token', 0, 5);

        self::assertSame('Bearer a-bearer-token', $headers['Authorization'] ?? null);
        self::assertArrayNotHasKey('X-MAL-CLIENT-ID', $headers);
    }

    public function testFetchAnimeListPageWithPagingNextButNoItemsHasNoNextPage(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, [
            'data' => [],
            'paging' => ['next' => 'https://api.myanimelist.net/v2/users/@me/animelist?offset=5'],
        ]));

        $client = $this->buildClient($httpClient);

        $page = $client->fetchAnimeListPage('a-bearer-token', 0, 5);

        self::assertSame([], $page['items']);
        self::assertFalse($page['hasNext']);
    }

    public function testFetchAnimeListPageWithPagingNextAndItemsHasNextPage(): void
    {
        $item = ['node' => ['id' => 1], 'list_status' => ['status' => 'watching']];

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, [
            'data' => [$item],
            'paging' => ['next' => 'https://api.myanimelist.net/v2/users/@me/animelist?offset=5'],
        ]));

        $client = $this->buildClient($httpClient);

        $page = $client->fetchAnimeListPage('a-bearer-token', 0, 5);

        self::assertSame([$item], $page['items']);
        self::assertTrue($page['hasNext']);
    }

    public function testFetchAnimeListPageWithoutPagingNextHasNoNextPage(): void
    {
        $item = ['node' => ['id' => 1], 'list_status' => ['status' => 'watching']];

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, ['data' => [$item]]));

        $client = $this->buildClient($httpClient);

        $page = $client->fetchAnimeListPage('a-bearer-token', 0, 5);

        self::assertFalse($page['hasNext']);
    }

    public function testFetchAnimeListPageShorterThanLimitWithPagingNextStillHasNextPage(): void
    {
        $item = ['node' => ['id' => 1], 'list_status' => ['status' => 'watching']];

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, [
            // One item, far short of the requested limit of 50 — a short page must not by
            // itself be read as "no more pages" (see class doc on fetchAnimeListPage()).
            'data' => [$item],
            'paging' => ['next' => 'https://api.myanimelist.net/v2/users/@me/animelist?offset=50'],
        ]));

        $client = $this->buildClient($httpClient);

        $page = $client->fetchAnimeListPage('a-bearer-token', 0, 50);

        self::assertTrue($page['hasNext']);
    }

    public function testFetchAnimeListPageNeverFollowsPagingNextUrlToAForeignHost(): void
    {
        $item = ['node' => ['id' => 1], 'list_status' => ['status' => 'watching']];

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::once())
            ->method('createRequest')
            ->with('GET', self::stringStartsWith('https://api.myanimelist.net/v2/users/@me/animelist?'))
            ->willReturn($this->fluentRequest());

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->jsonResponse(200, [
                'data' => [$item],
                'paging' => ['next' => 'https://attacker.example/users/@me/animelist?offset=5'],
            ]));

        $client = $this->buildClient($httpClient, $requestFactory);
        $page = $client->fetchAnimeListPage('a-bearer-token', 0, 5);

        self::assertTrue($page['hasNext']);
        self::assertArrayNotHasKey('next', $page);
        $encoded = json_encode($page, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('attacker.example', $encoded);
    }

    public function testUpdateListStatusSendsPatchToMyListStatusPath(): void
    {
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::once())
            ->method('createRequest')
            ->with('PATCH', 'https://api.myanimelist.net/v2/anime/123/my_list_status')
            ->willReturn($this->fluentRequest());

        $responseBody = ['status' => 'completed', 'num_episodes_watched' => 12, 'updated_at' => '2026-01-01T00:00:00+00:00'];
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, $responseBody));

        $client = $this->buildClient($httpClient, $requestFactory);
        $result = $client->updateListStatus('a-bearer-token', '123', 'watching', null);

        self::assertSame($responseBody, $result);
    }

    public function testUpdateListStatusRejectsInvalidAnimeIdWithoutSendingRequest(): void
    {
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::never())->method('createRequest');

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::never())->method('sendRequest');

        $client = $this->buildClient($httpClient, $requestFactory);

        $this->expectException(\InvalidArgumentException::class);
        $client->updateListStatus('a-bearer-token', '1/../x', 'watching', null);
    }

    public function testUpdateListStatusRejectsEmptyAnimeIdWithoutSendingRequest(): void
    {
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::never())->method('createRequest');

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::never())->method('sendRequest');

        $client = $this->buildClient($httpClient, $requestFactory);

        $this->expectException(\InvalidArgumentException::class);
        $client->updateListStatus('a-bearer-token', '', 'watching', null);
    }

    public function testUpdateListStatusBodyCarriesStatusAndIsRewatchingFalseWithoutEpisodesWhenNull(): void
    {
        $capturedBody = null;
        $streamFactory = $this->stubStreamFactoryCapturingBodyInto($capturedBody);

        $headers = [];
        $request = $this->fluentRequestCapturingHeadersInto($headers);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, ['status' => 'watching']));

        $client = $this->buildClient($httpClient, $requestFactory, $streamFactory);
        $client->updateListStatus('a-bearer-token', '123', 'watching', null);

        self::assertSame('Bearer a-bearer-token', $headers['Authorization'] ?? null);
        self::assertSame('application/x-www-form-urlencoded', $headers['Content-Type'] ?? null);
        self::assertSame(
            \sprintf('AnimeDB %s/%s (+https://anime-db.org/)', self::STUB_MANIFEST_ID, self::STUB_MANIFEST_VERSION),
            $headers['User-Agent'] ?? null,
        );

        parse_str($capturedBody ?? '', $parsed);
        self::assertSame('watching', $parsed['status'] ?? null);
        self::assertSame('false', $parsed['is_rewatching'] ?? null);
        self::assertArrayNotHasKey('num_watched_episodes', $parsed);
    }

    public function testUpdateListStatusBodyIncludesZeroWatchedEpisodes(): void
    {
        $capturedBody = null;
        $streamFactory = $this->stubStreamFactoryCapturingBodyInto($capturedBody);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, ['status' => 'watching']));

        $client = $this->buildClient($httpClient, null, $streamFactory);
        $client->updateListStatus('a-bearer-token', '123', 'watching', 0);

        parse_str($capturedBody ?? '', $parsed);
        self::assertSame('0', $parsed['num_watched_episodes'] ?? null);
    }

    public function testUpdateListStatus401ResponseThrowsUnauthorizedHttpException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(401, []));

        $client = $this->buildClient($httpClient);

        $this->expectException(UnauthorizedHttpException::class);
        $client->updateListStatus('a-bearer-token', '123', 'watching', null);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideNonSuccessNonUnauthorizedStatuses(): iterable
    {
        yield 'server error' => [500];
        yield 'not found' => [404];
    }

    /**
     * @dataProvider provideNonSuccessNonUnauthorizedStatuses
     */
    public function testUpdateListStatusNonSuccessStatusThrowsMalRequestException(int $status): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse($status, []));

        $client = $this->buildClient($httpClient);

        $this->expectException(MalRequestException::class);
        $client->updateListStatus('a-bearer-token', '123', 'watching', null);
    }

    public function testUpdateListStatusRetriesOn429ThenSucceeds(): void
    {
        $capturedBodies = [];
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            function (string $content) use (&$capturedBodies): StreamInterface {
                $capturedBodies[] = $content;

                $stream = $this->createMock(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);

                return $stream;
            },
        );

        $responses = [
            $this->jsonResponse(429, [], ['Retry-After' => '1']),
            $this->jsonResponse(200, ['status' => 'watching']),
        ];
        $call = 0;

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::exactly(2))
            ->method('sendRequest')
            ->willReturnCallback(static function () use (&$call, $responses): ResponseInterface {
                return $responses[$call++];
            });

        $client = $this->buildClient($httpClient, null, $streamFactory);
        $result = $client->updateListStatus('a-bearer-token', '123', 'watching', null);

        self::assertSame(['status' => 'watching'], $result);
        self::assertCount(2, $capturedBodies);
        self::assertSame($capturedBodies[0], $capturedBodies[1]);
    }

    public function testUpdateListStatusExhaustingTheRetryBudgetOn429ThrowsMalRequestException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::exactly(6))
            ->method('sendRequest')
            ->willReturn($this->jsonResponse(429, [], ['Retry-After' => '1']));

        $client = $this->buildClient($httpClient);

        $this->expectException(MalRequestException::class);
        $client->updateListStatus('a-bearer-token', '123', 'watching', null);
    }

    public function testUpdateListStatusTransportFailureThrowsMalRequestException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willThrowException($this->createMock(ClientExceptionInterface::class));

        $client = $this->buildClient($httpClient);

        $this->expectException(MalRequestException::class);
        $client->updateListStatus('a-bearer-token', '123', 'watching', null);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function jsonResponse(int $status, array $body, array $headers = []): ResponseInterface
    {
        return $this->rawResponse($status, json_encode($body, \JSON_THROW_ON_ERROR), $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function rawResponse(int $status, string $body, array $headers = []): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => $headers[$name] ?? '',
        );

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    private function fluentRequestCapturingHeadersInto(array &$headers): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$headers, $request): RequestInterface {
                $headers[$name] = $value;

                return $request;
            },
        );
        $request->method('withBody')->willReturnSelf();

        return $request;
    }

    private function fluentRequest(): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        return $request;
    }

    private function buildClient(
        ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): MalApiClient {
        if ($requestFactory === null) {
            $requestFactory = $this->createMock(RequestFactoryInterface::class);
            $requestFactory->method('createRequest')->willReturn($this->fluentRequest());
        }

        return new MalApiClient(
            $httpClient,
            $requestFactory,
            $streamFactory ?? $this->stubStreamFactory(),
            $this->noSleepRateLimiter(),
            $this->stubOwnManifest(),
        );
    }

    private function stubStreamFactory(): StreamFactoryInterface
    {
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            function (string $content): StreamInterface {
                $stream = $this->createMock(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);

                return $stream;
            },
        );

        return $streamFactory;
    }

    private function stubStreamFactoryCapturingBodyInto(?string &$capturedBody): StreamFactoryInterface
    {
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            function (string $content) use (&$capturedBody): StreamInterface {
                $capturedBody = $content;

                $stream = $this->createMock(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);

                return $stream;
            },
        );

        return $streamFactory;
    }

    private function stubOwnManifest(): OwnManifestInterface
    {
        $manifest = $this->createMock(OwnManifestInterface::class);
        $manifest->method('id')->willReturn(self::STUB_MANIFEST_ID);
        $manifest->method('version')->willReturn(self::STUB_MANIFEST_VERSION);

        return $manifest;
    }

    private function noSleepRateLimiter(): RateLimiter
    {
        $time = 0.0;

        return new RateLimiter(
            clock: static function () use (&$time): float {
                return $time;
            },
            sleeper: static function (float $seconds) use (&$time): void {
                $time += $seconds;
            },
        );
    }
}
