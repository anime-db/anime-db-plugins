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

namespace AnimeDb\Plugins\Tools\Tests;

use AnimeDb\Plugins\Tools\MirrorReachabilityChecker;

/**
 * In-memory {@see MirrorReachabilityChecker} test double: reachable unless the URL is listed in
 * $unreachableUrls, so {@see \AnimeDb\Plugins\Tools\MirrorAssetReachabilityVerifier} and its
 * callers can be exercised without real HTTP.
 */
final class FakeMirrorReachabilityChecker implements MirrorReachabilityChecker
{
    /** @var list<string> URLs to report as checked, in call order */
    public array $checked = [];

    /**
     * @param list<string> $unreachableUrls
     */
    public function __construct(
        private readonly array $unreachableUrls = [],
    ) {
    }

    public function isReachable(string $url): bool
    {
        $this->checked[] = $url;

        return !\in_array($url, $this->unreachableUrls, true);
    }
}
