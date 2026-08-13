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

namespace AnimeDb\Plugins\Tools;

/**
 * Real reachability check over HTTP(S), via PHP's stream wrappers (no ext-curl dependency — this
 * repo does not otherwise require it). Sends `HEAD` first; some shared hosting rejects `HEAD`
 * (`405`), so a `Range: bytes=0-0` `GET` is tried as a lighter-weight fallback probe. Retries with
 * a linear backoff before giving up: FTP-to-web propagation on shared hosting can lag by a few
 * seconds right after an upload, so a single immediate miss should not be treated as "unreachable".
 */
final class HttpMirrorReachabilityChecker implements MirrorReachabilityChecker
{
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $backoffMilliseconds = 1000,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function isReachable(string $url): bool
    {
        for ($attempt = 1; $attempt <= $this->maxAttempts; ++$attempt) {
            $status = $this->requestStatus($url, 'HEAD');

            if ($status !== null && $status >= 200 && $status < 300) {
                return true;
            }

            if ($status === 405) {
                // A ranged GET succeeds with 206 Partial Content, which already falls in [200,300).
                $status = $this->requestStatus($url, 'GET', "Range: bytes=0-0\r\n");
                if ($status !== null && $status >= 200 && $status < 300) {
                    return true;
                }
            }

            if ($attempt < $this->maxAttempts) {
                usleep($this->backoffMilliseconds * 1000 * $attempt);
            }
        }

        return false;
    }

    private function requestStatus(string $url, string $method, string $extraHeader = ''): ?int
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $extraHeader,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => true,
            ],
        ]);

        // @get_headers(): suppress the warning ext/streams emits on connect failure — that case is
        // reported below as a null status, same as any other unreachable outcome.
        $headers = @get_headers($url, false, $context);

        if ($headers === false || !isset($headers[0])) {
            return null;
        }

        // The last "HTTP/..." status line wins (redirects prepend earlier ones before follow_location
        // appends the final response's headers).
        $status = null;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }
}
