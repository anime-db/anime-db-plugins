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

use AnimeDb\Plugins\Tools\MirrorAssetPublisher;
use AnimeDb\Plugins\Tools\MirrorAssetReachabilityVerifier;
use AnimeDb\Plugins\Tools\MirrorBackfillPublisher;
use AnimeDb\Plugins\Tools\MirrorCredential;
use PHPUnit\Framework\TestCase;

final class MirrorBackfillPublisherTest extends TestCase
{
    public function testUploadsAndVerifiesEveryHistoricalRelease(): void
    {
        $transport = new FakeMirrorTransport();
        $source = new FakeReleaseAssetSource([
            ['id' => 'animedb-shikimori', 'version' => '0.1.0'],
            ['id' => 'animedb-shikimori', 'version' => '0.2.0'],
        ]);
        $checker = new FakeMirrorReachabilityChecker();

        $publisher = new MirrorBackfillPublisher(
            $source,
            new MirrorAssetPublisher($transport),
            new MirrorAssetReachabilityVerifier($checker),
        );

        $publisher->backfill($this->credential());

        self::assertSame(
            [
                '/mirror/animedb-shikimori/0.1.0/plugin.zip',
                '/mirror/animedb-shikimori/0.1.0/manifest.json',
                '/mirror/animedb-shikimori/0.2.0/plugin.zip',
                '/mirror/animedb-shikimori/0.2.0/manifest.json',
            ],
            $transport->uploaded,
        );
        self::assertCount(4, $checker->checked);
    }

    public function testUnreachableAssetAfterUploadIsHardFail(): void
    {
        $source = new FakeReleaseAssetSource([['id' => 'animedb-shikimori', 'version' => '0.1.0']]);
        $checker = new FakeMirrorReachabilityChecker(['https://mirror.tld/animedb-shikimori/0.1.0/plugin.zip']);

        $publisher = new MirrorBackfillPublisher(
            $source,
            new MirrorAssetPublisher(new FakeMirrorTransport()),
            new MirrorAssetReachabilityVerifier($checker),
        );

        $this->expectException(\RuntimeException::class);

        $publisher->backfill($this->credential());
    }

    public function testMissingAssetInAReleaseIsHardFail(): void
    {
        $source = new FakeReleaseAssetSource(
            [['id' => 'animedb-shikimori', 'version' => '0.1.0']],
            omitAssetFor: ['animedb-shikimori/0.1.0'],
        );

        $publisher = new MirrorBackfillPublisher(
            $source,
            new MirrorAssetPublisher(new FakeMirrorTransport()),
            new MirrorAssetReachabilityVerifier(new FakeMirrorReachabilityChecker()),
        );

        $this->expectException(\RuntimeException::class);

        $publisher->backfill($this->credential());
    }

    public function testInvalidPublicUrlIsRejectedBeforeAnyUpload(): void
    {
        $transport = new FakeMirrorTransport();
        $source = new FakeReleaseAssetSource([['id' => 'animedb-shikimori', 'version' => '0.1.0']]);

        $publisher = new MirrorBackfillPublisher(
            $source,
            new MirrorAssetPublisher($transport),
            new MirrorAssetReachabilityVerifier(new FakeMirrorReachabilityChecker()),
        );

        $this->expectException(\RuntimeException::class);

        try {
            $publisher->backfill($this->credential('http://mirror.tld/<id>/<version>/<file>'));
        } finally {
            self::assertSame([], $transport->uploaded);
        }
    }

    public function testNoReleasesIsANoOp(): void
    {
        $transport = new FakeMirrorTransport();
        $publisher = new MirrorBackfillPublisher(
            new FakeReleaseAssetSource([]),
            new MirrorAssetPublisher($transport),
            new MirrorAssetReachabilityVerifier(new FakeMirrorReachabilityChecker()),
        );

        $publisher->backfill($this->credential());

        self::assertSame([], $transport->uploaded);
    }

    private function credential(string $publicUrl = 'https://mirror.tld/<id>/<version>/<file>'): MirrorCredential
    {
        return new MirrorCredential('mirror1', 'a.tld', 21, 'u', 'p', '/mirror', 'ftps', $publicUrl);
    }
}
