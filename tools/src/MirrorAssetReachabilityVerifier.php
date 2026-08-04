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

namespace AnimeDb\Plugins\Tools;

/**
 * HEAD-verifies one plugin version's assets against every mirror's `public_url` template, after
 * {@see MirrorAssetPublisher} has already uploaded them. Produces one {@see MirrorReachabilityReport}
 * per (mirror, file) pair rather than throwing — turning an unreachable result into a hard failure
 * (backfill, issue #26) or a soft, logged-and-continue one (a release, same issue) is entirely up
 * to the caller.
 */
final class MirrorAssetReachabilityVerifier
{
    public function __construct(
        private readonly MirrorReachabilityChecker $checker,
    ) {
    }

    /**
     * @param array<string, MirrorCredential> $mirrors    as returned by
     *                                                    {@see MirrorCredentialsParser::parse()}
     * @param list<string>                    $assetFiles file names, e.g. ["plugin.zip", "manifest.json"]
     *
     * @return list<MirrorReachabilityReport>
     */
    public function verify(array $mirrors, string $pluginId, string $version, array $assetFiles): array
    {
        $reports = [];

        foreach ($mirrors as $credential) {
            foreach ($assetFiles as $file) {
                $url = MirrorAssetUrl::build($credential->publicUrl, $pluginId, $version, $file);
                $reports[] = new MirrorReachabilityReport($credential->id, $url, $this->checker->isReachable($url));
            }
        }

        return $reports;
    }
}
