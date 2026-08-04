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

use AnimeDb\Plugins\Tools\MirrorAssetReachabilityVerifier;
use AnimeDb\Plugins\Tools\MirrorCredential;
use PHPUnit\Framework\TestCase;

final class MirrorAssetReachabilityVerifierTest extends TestCase
{
    public function testVerifiesEveryAssetOfEveryMirrorAndReportsPerFile(): void
    {
        $checker = new FakeMirrorReachabilityChecker(['https://second.tld/animedb-shikimori/0.1.0/plugin.zip']);
        $mirrors = [
            'mirror1' => new MirrorCredential('mirror1', 'a.tld', 21, 'u', 'p', '/d', 'ftps', 'https://mirror1.example.org/<id>/<version>/<file>'),
            'second' => new MirrorCredential('second', 'b.tld', 21, 'u', 'p', '/d', 'ftp', 'https://second.tld/<id>/<version>/<file>'),
        ];

        $reports = (new MirrorAssetReachabilityVerifier($checker))->verify(
            $mirrors,
            'animedb-shikimori',
            '0.1.0',
            ['plugin.zip', 'manifest.json'],
        );

        self::assertCount(4, $reports);
        self::assertSame('https://mirror1.example.org/animedb-shikimori/0.1.0/plugin.zip', $reports[0]->url);
        self::assertTrue($reports[0]->reachable);
        self::assertSame('https://second.tld/animedb-shikimori/0.1.0/plugin.zip', $reports[2]->url);
        self::assertFalse($reports[2]->reachable);
        self::assertSame('second', $reports[2]->mirrorId);
    }

    public function testNoMirrorsYieldsNoReports(): void
    {
        $reports = (new MirrorAssetReachabilityVerifier(new FakeMirrorReachabilityChecker()))->verify(
            [],
            'animedb-shikimori',
            '0.1.0',
            ['plugin.zip'],
        );

        self::assertSame([], $reports);
    }
}
