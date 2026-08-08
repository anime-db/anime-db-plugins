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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Sync;

use AnimeDb\PluginContracts\OAuth\OAuthTokenExchangeException;
use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use AnimeDb\Plugins\AnimedbShikimori\Http\UnauthorizedHttpException;
use AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthClient;
use AnimeDb\Plugins\AnimedbShikimori\Sync\ShikimoriAuthRetrier;
use PHPUnit\Framework\TestCase;

final class ShikimoriAuthRetrierTest extends TestCase
{
    public function testCallPassesCurrentTokenThroughOnSuccess(): void
    {
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        $oauth->method('accessToken')->willReturn('token-1');
        $oauth->expects(self::never())->method('refreshAccessToken');

        $retrier = new ShikimoriAuthRetrier($oauth);

        $result = $retrier->call(static fn (string $bearer): string => 'result-for-'.$bearer);

        self::assertSame('result-for-token-1', $result);
    }

    public function testNoStoredTokenThrowsReauthRequiredImmediately(): void
    {
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        $oauth->method('accessToken')->willReturn(null);
        $oauth->expects(self::never())->method('refreshAccessToken');
        $oauth->expects(self::never())->method('disconnect');

        $retrier = new ShikimoriAuthRetrier($oauth);

        $this->expectException(ReauthRequiredException::class);
        $retrier->call(static fn (string $bearer): string => 'unreachable');
    }

    public function test401ThenSuccessfulRefreshRetriesOnceWithNewToken(): void
    {
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        $oauth->method('accessToken')->willReturnOnConsecutiveCalls('old-token', 'new-token');
        $oauth->expects(self::once())->method('refreshAccessToken');
        $oauth->expects(self::never())->method('disconnect');

        $retrier = new ShikimoriAuthRetrier($oauth);

        $attempts = [];
        $result = $retrier->call(static function (string $bearer) use (&$attempts): string {
            $attempts[] = $bearer;
            if ($bearer === 'old-token') {
                throw new UnauthorizedHttpException('HTTP 401');
            }

            return 'ok-with-'.$bearer;
        });

        self::assertSame(['old-token', 'new-token'], $attempts);
        self::assertSame('ok-with-new-token', $result);
    }

    public function test401ThenFailedRefreshWithChangedTokenRetriesWithCurrentTokenWithoutTouchingStore(): void
    {
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        // Rotation race: a concurrent process already refreshed the token by the time this
        // process's own refresh attempt is rejected.
        $oauth->method('accessToken')->willReturnOnConsecutiveCalls('old-token', 'raced-token');
        $oauth->method('refreshAccessToken')->willThrowException(new OAuthTokenExchangeException('invalid_grant'));
        $oauth->expects(self::never())->method('disconnect');

        $retrier = new ShikimoriAuthRetrier($oauth);

        $attempts = [];
        $result = $retrier->call(static function (string $bearer) use (&$attempts): string {
            $attempts[] = $bearer;
            if ($bearer === 'old-token') {
                throw new UnauthorizedHttpException('HTTP 401');
            }

            return 'ok-with-'.$bearer;
        });

        self::assertSame(['old-token', 'raced-token'], $attempts);
        self::assertSame('ok-with-raced-token', $result);
    }

    public function test401ThenFailedRefreshWithUnchangedTokenDisconnectsAndThrowsReauthRequired(): void
    {
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        $oauth->method('accessToken')->willReturn('old-token');
        $oauth->method('refreshAccessToken')->willThrowException(new OAuthTokenExchangeException('invalid_grant'));
        $oauth->expects(self::once())->method('disconnect');

        $retrier = new ShikimoriAuthRetrier($oauth);

        $this->expectException(ReauthRequiredException::class);
        $retrier->call(static function (string $bearer): never {
            throw new UnauthorizedHttpException('HTTP 401');
        });
    }

    public function test401ThenNoRefreshTokenStoredDisconnectsAndThrowsReauthRequired(): void
    {
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        $oauth->method('accessToken')->willReturn('old-token');
        $oauth->method('refreshAccessToken')->willThrowException(
            new \LogicException('No OAuth refresh token stored; complete handleCallback() before refreshing.'),
        );
        $oauth->expects(self::once())->method('disconnect');

        $retrier = new ShikimoriAuthRetrier($oauth);

        $this->expectException(ReauthRequiredException::class);
        $retrier->call(static function (string $bearer): never {
            throw new UnauthorizedHttpException('HTTP 401');
        });
    }
}
