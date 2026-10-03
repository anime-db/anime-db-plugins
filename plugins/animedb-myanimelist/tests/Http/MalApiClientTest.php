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
use AnimeDb\Plugins\AnimedbMyanimelist\Http\RateLimiter;
use AnimeDb\Plugins\AnimedbMyanimelist\Http\UnauthorizedHttpException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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

        return $request;
    }

    private function fluentRequest(): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();

        return $request;
    }

    private function buildClient(ClientInterface $httpClient, ?RequestFactoryInterface $requestFactory = null): MalApiClient
    {
        if ($requestFactory === null) {
            $requestFactory = $this->createMock(RequestFactoryInterface::class);
            $requestFactory->method('createRequest')->willReturn($this->fluentRequest());
        }

        return new MalApiClient($httpClient, $requestFactory, $this->noSleepRateLimiter(), $this->stubOwnManifest());
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
