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

use AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthClient;
use AnimeDb\Plugins\AnimedbShikimori\OAuth\ShikimoriOAuthStartController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ShikimoriOAuthStartControllerTest extends TestCase
{
    public function testRedirectsToTheVendorAuthorizeUrl(): void
    {
        $oauth = $this->createMock(ShikimoriOAuthClient::class);
        $oauth->method('buildAuthorizeUrl')->willReturn('https://shikimori.io/oauth/authorize?state=abc');

        $controller = new ShikimoriOAuthStartController($oauth);
        $response = $controller();

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('https://shikimori.io/oauth/authorize?state=abc', $response->headers->get('Location'));
    }
}
