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

/*
 * Tiny router for PHP's built-in web server, used by HttpMirrorReachabilityCheckerTest to exercise
 * HttpMirrorReachabilityChecker against real HTTP responses without any external network.
 */

$path = parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($path === '/ok') {
    http_response_code(200);
    exit;
}

if ($path === '/notfound') {
    http_response_code(404);
    exit;
}

// HEAD is rejected (405), matching shared hosting that disallows it; a ranged GET succeeds.
if ($path === '/head-405') {
    http_response_code($method === 'HEAD' ? 405 : 206);
    exit;
}

// Fails once, then succeeds — exercises the retry/backoff path. State is a counter file whose
// path is passed via ?counter=..., so each test gets an isolated, fresh counter.
if ($path === '/flaky') {
    $counterFile = $_GET['counter'] ?? null;
    $count = ($counterFile !== null && is_file($counterFile)) ? (int) file_get_contents($counterFile) : 0;
    ++$count;
    if ($counterFile !== null) {
        file_put_contents($counterFile, (string) $count);
    }
    http_response_code($count < 2 ? 500 : 200);
    exit;
}

http_response_code(404);
