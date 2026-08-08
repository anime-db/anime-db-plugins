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

namespace AnimeDb\Plugins\AnimedbShikimori\Sync;

use AnimeDb\PluginContracts\OAuth\OAuthTokenExchangeException;
use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use AnimeDb\Plugins\AnimedbShikimori\Http\UnauthorizedHttpException;
use AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthClient;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Single 401-handling policy shared by {@see \AnimeDb\Plugins\AnimedbShikimori\ShikimoriFiller::push()}
 * (REST) and {@see \AnimeDb\Plugins\AnimedbShikimori\ShikimoriFiller::pull()} (GraphQL): both
 * transports throw the same {@see UnauthorizedHttpException} on HTTP 401, so one retry policy
 * covers them, instead of duplicating this logic per transport.
 *
 * On a 401, {@see self::call()}:
 *
 * 1. Attempts {@see ShikimoriOAuthClient::refreshAccessToken()}.
 * 2. If the refresh succeeds, retries the request once with the new access token.
 * 3. If the refresh definitively fails (the token endpoint rejected it, or there was no
 *    refresh token to use — {@see OAuthTokenExchangeException}/`\LogicException`), it
 *    distinguishes a rotation race from a genuinely dead session: it re-reads
 *    {@see ShikimoriOAuthClient::accessToken()} and compares it against the token the failing
 *    request used. If it changed, a concurrent process already refreshed successfully — this
 *    call retries with that current token and does not touch the token store. If it did not
 *    change, the session is dead: {@see ShikimoriOAuthClient::disconnect()} clears the now
 *    confirmed-dead tokens and this method throws {@see ReauthRequiredException}.
 * 4. A network failure *during the refresh call itself* ({@see ClientExceptionInterface}) is
 *    neither of those — it says nothing about whether the session is alive, so it is not
 *    treated as a dead session (no `disconnect()`) and is simply propagated.
 *
 * A failed refresh alone never clears stored tokens — only the confirmed-not-a-race branch
 * above does. No lock is taken around the compare-and-retry: the settings store's own
 * `update()` is already an atomic read-modify-write (see {@see ShikimoriOAuthClient}'s parent
 * class doc), so there is nothing left for a lock in this class to protect.
 */
class ShikimoriAuthRetrier
{
    public function __construct(
        private readonly ShikimoriOAuthClient $oauth,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(string $bearer): T $makeRequest must throw {@see UnauthorizedHttpException}
     *                                                 on HTTP 401, not swallow it
     *
     * @return T
     *
     * @throws ReauthRequiredException no token is stored, or the session is confirmed dead
     */
    public function call(callable $makeRequest): mixed
    {
        $before = $this->oauth->accessToken();
        if ($before === null) {
            throw new ReauthRequiredException('Shikimori OAuth session is not connected.');
        }

        try {
            return $makeRequest($before);
        } catch (UnauthorizedHttpException) {
            return $this->retryAfterUnauthorized($makeRequest, $before);
        }
    }

    /**
     * @template T
     *
     * @param callable(string $bearer): T $makeRequest
     *
     * @return T
     */
    private function retryAfterUnauthorized(callable $makeRequest, string $before): mixed
    {
        try {
            $this->oauth->refreshAccessToken();
        } catch (ClientExceptionInterface $exception) {
            // A transient transport failure while refreshing says nothing about whether the
            // session is alive: propagate as-is, do not touch the token store.
            throw $exception;
        } catch (OAuthTokenExchangeException|\LogicException) {
            return $this->afterFailedRefresh($makeRequest, $before);
        }

        $after = $this->oauth->accessToken();
        if ($after === null) {
            // Defensive: a successful refresh should always leave a token behind.
            $this->oauth->disconnect();
            throw new ReauthRequiredException('Shikimori OAuth refresh left no access token.');
        }

        return $makeRequest($after);
    }

    /**
     * @template T
     *
     * @param callable(string $bearer): T $makeRequest
     *
     * @return T
     */
    private function afterFailedRefresh(callable $makeRequest, string $before): mixed
    {
        $after = $this->oauth->accessToken();

        if ($after !== null && $after !== $before) {
            // Rotation race: a concurrent process already refreshed successfully. The token in
            // the store is current — retry with it, leave the store untouched.
            return $makeRequest($after);
        }

        // Not a race: the token is unchanged and refresh was rejected, so the session is
        // confirmed dead. Clearing it now is safe precisely because it is confirmed dead.
        $this->oauth->disconnect();
        throw new ReauthRequiredException('Shikimori OAuth refresh failed; reauthorization is required.');
    }
}
