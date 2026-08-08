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

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use AnimeDb\Plugins\AnimedbShikimori\Http\RateLimiter;
use AnimeDb\Plugins\AnimedbShikimori\Http\RestRequestException;
use AnimeDb\Plugins\AnimedbShikimori\Http\ShikimoriRestClient;
use AnimeDb\Plugins\AnimedbShikimori\Http\UnauthorizedHttpException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class ShikimoriRestClientTest extends TestCase
{
    private const STUB_MANIFEST_ID = 'animedb-shikimori-stub';
    private const STUB_MANIFEST_VERSION = '9.9.9';

    public function testFindUserRateIdReturnsExistingId(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn(
            $this->jsonResponse(200, [['id' => 42, 'user_id' => 1, 'target_id' => 20, 'target_type' => 'Anime']]),
        );

        $client = $this->buildClient($httpClient);

        self::assertSame('42', $client->findUserRateId('a-token', '1', '20'));
    }

    public function testFindUserRateIdReturnsNullWhenListIsEmpty(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, []));

        $client = $this->buildClient($httpClient);

        self::assertNull($client->findUserRateId('a-token', '1', '20'));
    }

    public function testCreateUserRateSendsUserIdTargetAndStatus(): void
    {
        $body = null;

        $request = $this->fluentRequest();
        $request->method('withBody')->willReturnCallback(function (StreamInterface $stream) use ($request, &$body): RequestInterface {
            $body = (string) $stream;

            return $request;
        });

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::once())
            ->method('createRequest')
            ->with('POST', 'https://shikimori.io/api/v2/user_rates')
            ->willReturn($request);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            fn (string $content) => $this->stringStream($content),
        );

        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('read')->willReturn([]);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(201, ['id' => 1]));

        $client = new ShikimoriRestClient(
            $httpClient,
            $requestFactory,
            $streamFactory,
            $settings,
            $this->noSleepRateLimiter(),
            $this->stubOwnManifest(),
        );

        $client->createUserRate('a-token', '7', '20', 'watching');

        self::assertSame(
            ['user_rate' => ['user_id' => '7', 'target_id' => '20', 'target_type' => 'Anime', 'status' => 'watching']],
            json_decode((string) $body, true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testUpdateUserRateSendsPatchWithOnlyStatus(): void
    {
        $body = null;

        $request = $this->fluentRequest();
        $request->method('withBody')->willReturnCallback(function (StreamInterface $stream) use ($request, &$body): RequestInterface {
            $body = (string) $stream;

            return $request;
        });

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::once())
            ->method('createRequest')
            ->with('PATCH', 'https://shikimori.io/api/v2/user_rates/42')
            ->willReturn($request);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            fn (string $content) => $this->stringStream($content),
        );

        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('read')->willReturn([]);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, ['id' => 42]));

        $client = new ShikimoriRestClient(
            $httpClient,
            $requestFactory,
            $streamFactory,
            $settings,
            $this->noSleepRateLimiter(),
            $this->stubOwnManifest(),
        );

        $client->updateUserRate('a-token', '42', 'completed');

        self::assertSame(
            ['user_rate' => ['status' => 'completed']],
            json_decode((string) $body, true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testSendsAuthorizationAndUserAgentHeaders(): void
    {
        $headers = [];
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$headers, $request): RequestInterface {
                $headers[$name] = $value;

                return $request;
            },
        );

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));

        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('read')->willReturn([]);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, []));

        $client = new ShikimoriRestClient(
            $httpClient,
            $requestFactory,
            $streamFactory,
            $settings,
            $this->noSleepRateLimiter(),
            $this->stubOwnManifest(),
        );

        $client->findUserRateId('the-bearer-token', '1', '20');

        self::assertSame('Bearer the-bearer-token', $headers['Authorization'] ?? null);
        self::assertSame(
            \sprintf('AnimeDB %s/%s (+https://anime-db.org/)', self::STUB_MANIFEST_ID, self::STUB_MANIFEST_VERSION),
            $headers['User-Agent'] ?? null,
        );
    }

    public function testUsesApiEndpointFromSettingsStore(): void
    {
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::once())
            ->method('createRequest')
            ->with('GET', self::stringStartsWith('https://shikimori.one/api/v2/user_rates'))
            ->willReturn($this->fluentRequest());

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));

        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('read')->willReturn(['api_endpoint' => 'https://shikimori.one']);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(200, []));

        $client = new ShikimoriRestClient(
            $httpClient,
            $requestFactory,
            $streamFactory,
            $settings,
            $this->noSleepRateLimiter(),
            $this->stubOwnManifest(),
        );

        $client->findUserRateId('a-token', '1', '20');
    }

    public function test401ResponseThrowsUnauthorizedHttpException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(401, []));

        $client = $this->buildClient($httpClient);

        $this->expectException(UnauthorizedHttpException::class);
        $client->findUserRateId('a-token', '1', '20');
    }

    public function testOtherNonSuccessStatusThrowsRestRequestException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->jsonResponse(422, []));

        $client = $this->buildClient($httpClient);

        $this->expectException(RestRequestException::class);
        $client->findUserRateId('a-token', '1', '20');
    }

    public function testTransportFailureThrowsRestRequestException(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willThrowException($this->createMock(ClientExceptionInterface::class));

        $client = $this->buildClient($httpClient);

        $this->expectException(RestRequestException::class);
        $client->findUserRateId('a-token', '1', '20');
    }

    /**
     * @param array<int|string, mixed> $body
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn(json_encode($body, \JSON_THROW_ON_ERROR));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    private function stringStream(string $content): StreamInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($content);

        return $stream;
    }

    private function fluentRequest(): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        return $request;
    }

    private function buildClient(ClientInterface $httpClient): ShikimoriRestClient
    {
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($this->fluentRequest());

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));

        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('read')->willReturn([]);

        return new ShikimoriRestClient(
            $httpClient,
            $requestFactory,
            $streamFactory,
            $settings,
            $this->noSleepRateLimiter(),
            $this->stubOwnManifest(),
        );
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
