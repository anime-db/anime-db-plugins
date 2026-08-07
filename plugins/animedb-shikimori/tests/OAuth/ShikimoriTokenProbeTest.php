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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\OAuth;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriTokenProbe;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ShikimoriTokenProbeTest extends TestCase
{
    public function testReturnsTrueOnA2xxWhoAmIResponse(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        self::assertTrue($this->buildProbe($httpClient)->check('some-token'));
    }

    public function testReturnsFalseOnANon2xxResponseWithoutThrowing(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(401);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        self::assertFalse($this->buildProbe($httpClient)->check('some-token'));
    }

    public function testReturnsFalseOnATransportFailureWithoutThrowing(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willThrowException($this->createMock(ClientExceptionInterface::class));

        self::assertFalse($this->buildProbe($httpClient)->check('some-token'));
    }

    public function testSendsBearerAuthorizationAndUserAgentHeaders(): void
    {
        $capturedHeaders = [];

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnCallback(
            function (string $name, string $value) use (&$capturedHeaders, $request): RequestInterface {
                $capturedHeaders[$name] = $value;

                return $request;
            },
        );

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::once())
            ->method('createRequest')
            ->with('GET', 'https://shikimori.io/api/users/whoami')
            ->willReturn($request);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        $manifest = $this->createMock(OwnManifestInterface::class);
        $manifest->method('id')->willReturn('animedb-shikimori');
        $manifest->method('version')->willReturn('0.3.0');

        $probe = new ShikimoriTokenProbe($httpClient, $requestFactory, $manifest);

        self::assertTrue($probe->check('the-access-token'));
        self::assertSame('Bearer the-access-token', $capturedHeaders['Authorization']);
        self::assertSame('AnimeDB animedb-shikimori/0.3.0 (+https://anime-db.org/)', $capturedHeaders['User-Agent']);
    }

    private function buildProbe(ClientInterface $httpClient): ShikimoriTokenProbe
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        return new ShikimoriTokenProbe($httpClient, $requestFactory, $this->createMock(OwnManifestInterface::class));
    }
}
