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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Http;

use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlClient;
use AnimeDb\Plugins\AnimedbShikimori\Http\GraphQlRequestException;
use AnimeDb\Plugins\AnimedbShikimori\Http\RateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class GraphQlClientTest extends TestCase
{
    public function testQueryReturnsDataPayloadOnSuccess(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn(
            $this->jsonResponse(200, ['data' => ['animes' => [['id' => '20', 'name' => 'Naruto']]]]),
        );

        $client = $this->buildClient($httpClient);

        $data = $client->query('query {}');

        self::assertSame(['animes' => [['id' => '20', 'name' => 'Naruto']]], $data);
    }

    public function testQueryTreatsEmptyAnimesAsNotFoundRatherThanError(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, ['data' => ['animes' => []]]));

        $client = $this->buildClient($httpClient);

        self::assertSame(['animes' => []], $client->query('query {}'));
    }

    public function testTransportFailureThrows(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willThrowException($this->createMock(ClientExceptionInterface::class));

        $client = $this->buildClient($httpClient);

        $this->expectException(GraphQlRequestException::class);
        $client->query('query {}');
    }

    public function testNonSuccessHttpStatusThrows(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(500, []));

        $client = $this->buildClient($httpClient);

        $this->expectException(GraphQlRequestException::class);
        $client->query('query {}');
    }

    public function testNonEmptyGraphQlErrorsThrows(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn(
            $this->jsonResponse(200, ['errors' => [['message' => 'something broke']]]),
        );

        $client = $this->buildClient($httpClient);

        $this->expectException(GraphQlRequestException::class);
        $this->expectExceptionMessageMatches('/something broke/');
        $client->query('query {}');
    }

    public function testRetriesAfter429ThenSucceeds(): void
    {
        $responses = [
            $this->jsonResponse(429, [], ['Retry-After' => '1']),
            $this->jsonResponse(200, ['data' => ['animes' => []]]),
        ];
        $call = 0;

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::exactly(2))
            ->method('sendRequest')
            ->willReturnCallback(static function () use (&$call, $responses): ResponseInterface {
                return $responses[$call++];
            });

        $client = $this->buildClient($httpClient);

        self::assertSame(['animes' => []], $client->query('query {}'));
    }

    public function testExhaustingTheRetryBudgetOn429Throws(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::exactly(6))
            ->method('sendRequest')
            ->willReturn($this->jsonResponse(429, [], ['Retry-After' => '1']));

        $client = $this->buildClient($httpClient);

        $this->expectException(GraphQlRequestException::class);
        $client->query('query {}');
    }

    public function testHeartbeatIsCalledOnEveryRetryAttempt(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(429, [], ['Retry-After' => '1']));

        $client = $this->buildClient($httpClient);

        $heartbeats = 0;
        try {
            $client->query('query {}', [], static function () use (&$heartbeats): void {
                ++$heartbeats;
            });
        } catch (GraphQlRequestException) {
            // budget exhaustion is expected here — only the heartbeat count matters
        }

        self::assertGreaterThan(1, $heartbeats);
    }

    public function testSendsRequiredHeadersAndDefaultEndpoint(): void
    {
        $headers = [];
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$headers, $request): RequestInterface {
                $headers[$name] = $value;

                return $request;
            },
        );
        $request->method('withBody')->willReturnSelf();

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::once())
            ->method('createRequest')
            ->with('POST', 'https://shikimori.io/api/graphql')
            ->willReturn($request);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));

        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('read')->willReturn([]);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, ['data' => []]));

        $client = new GraphQlClient($httpClient, $requestFactory, $streamFactory, $settings, $this->noSleepRateLimiter());
        $client->query('query {}');

        $manifest = json_decode((string) file_get_contents(__DIR__.'/../../manifest.json'), true);

        self::assertSame('application/json', $headers['Content-Type'] ?? null);
        self::assertSame(
            \sprintf('AnimeDB animedb-shikimori/%s (+https://anime-db.org/)', $manifest['version']),
            $headers['User-Agent'] ?? null,
        );
    }

    public function testUsesApiEndpointFromSettingsStore(): void
    {
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::once())
            ->method('createRequest')
            ->with('POST', 'https://shikimori.one/api/graphql')
            ->willReturn($this->fluentRequest());

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));

        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('read')->willReturn(['api_endpoint' => 'https://shikimori.one']);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, ['data' => []]));

        $client = new GraphQlClient($httpClient, $requestFactory, $streamFactory, $settings, $this->noSleepRateLimiter());
        $client->query('query {}');
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function jsonResponse(int $status, array $body, array $headers = []): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn(json_encode($body, \JSON_THROW_ON_ERROR));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => $headers[$name] ?? '',
        );

        return $response;
    }

    private function fluentRequest(): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        return $request;
    }

    private function buildClient(ClientInterface $httpClient, array $settings = []): GraphQlClient
    {
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($this->fluentRequest());

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));

        $settingsStore = $this->createMock(SettingsStoreInterface::class);
        $settingsStore->method('read')->willReturn($settings);

        return new GraphQlClient($httpClient, $requestFactory, $streamFactory, $settingsStore, $this->noSleepRateLimiter());
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
