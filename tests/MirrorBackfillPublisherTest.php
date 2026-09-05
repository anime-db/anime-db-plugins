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

namespace AnimeDb\Plugins\Tools\Tests;

use AnimeDb\Plugins\Tools\MirrorAssetPublisher;
use AnimeDb\Plugins\Tools\MirrorAssetReachabilityVerifier;
use AnimeDb\Plugins\Tools\MirrorBackfillPublisher;
use AnimeDb\Plugins\Tools\MirrorCredential;
use AnimeDb\Plugins\Tools\ReleaseAssetSource;
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

    public function testUnwritableTempDirectoryIsAHardFailWithAClearMessage(): void
    {
        // sys_get_temp_dir() caches its resolved value for the life of the process, so
        // overriding TMPDIR here would not be observed — strip write permission from the real
        // temp directory instead. Root ignores permission bits, so mkdir() would still succeed;
        // skip rather than false-pass in that environment.
        if (posix_getuid() === 0) {
            self::markTestSkipped('Cannot deny write access to a directory while running as root.');
        }

        $tempRoot = sys_get_temp_dir();
        $originalMode = fileperms($tempRoot) & 0777;
        self::assertTrue(chmod($tempRoot, 0500));

        $publisher = new MirrorBackfillPublisher(
            new FakeReleaseAssetSource([['id' => 'animedb-shikimori', 'version' => '0.1.0']]),
            new MirrorAssetPublisher(new FakeMirrorTransport()),
            new MirrorAssetReachabilityVerifier(new FakeMirrorReachabilityChecker()),
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/Failed to create temporary directory/');

            $publisher->backfill($this->credential());
        } finally {
            chmod($tempRoot, $originalMode);
        }
    }

    public function testCleanupRemovesEveryFileLeftInTheTempDirectoryNotJustTheKnownNames(): void
    {
        $capturedTempDir = null;
        $source = new class($capturedTempDir) implements ReleaseAssetSource {
            public function __construct(private mixed &$capturedTempDir)
            {
            }

            public function listReleases(): array
            {
                return [['id' => 'animedb-shikimori', 'version' => '0.1.0']];
            }

            public function downloadAssets(string $pluginId, string $version, string $destDir): array
            {
                $this->capturedTempDir = $destDir;
                file_put_contents($destDir.'/plugin.zip', 'zip-bytes');
                file_put_contents($destDir.'/manifest.json', '{}');
                // Simulates a leftover partially-downloaded/temp file that is not one of the two
                // names the old cleanup knew about.
                file_put_contents($destDir.'/gh-download.tmp', 'partial');

                return ['plugin.zip', 'manifest.json'];
            }
        };

        $publisher = new MirrorBackfillPublisher(
            $source,
            new MirrorAssetPublisher(new FakeMirrorTransport()),
            new MirrorAssetReachabilityVerifier(new FakeMirrorReachabilityChecker()),
        );

        $publisher->backfill($this->credential());

        self::assertNotNull($capturedTempDir);
        self::assertDirectoryDoesNotExist($capturedTempDir);
    }

    public function testCleanupFailureIsAWarningNotAFailedBackfill(): void
    {
        $capturedTempDir = null;
        $source = new class($capturedTempDir) implements ReleaseAssetSource {
            public function __construct(private mixed &$capturedTempDir)
            {
            }

            public function listReleases(): array
            {
                return [['id' => 'animedb-shikimori', 'version' => '0.1.0']];
            }

            public function downloadAssets(string $pluginId, string $version, string $destDir): array
            {
                $this->capturedTempDir = $destDir;
                file_put_contents($destDir.'/plugin.zip', 'zip-bytes');
                file_put_contents($destDir.'/manifest.json', '{}');
                // A leftover subdirectory is never cleaned up (cleanup only unlinks files), so
                // rmdir() on the temp dir itself is expected to fail.
                mkdir($destDir.'/leftover-subdir');

                return ['plugin.zip', 'manifest.json'];
            }
        };

        $publisher = new MirrorBackfillPublisher(
            $source,
            new MirrorAssetPublisher(new FakeMirrorTransport()),
            new MirrorAssetReachabilityVerifier(new FakeMirrorReachabilityChecker()),
        );

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        }, \E_USER_WARNING);

        try {
            $publisher->backfill($this->credential());
        } finally {
            restore_error_handler();
        }

        self::assertNotNull($capturedTempDir);
        self::assertDirectoryExists($capturedTempDir);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Failed to remove temporary directory', $warnings[0]);

        self::removeDirectoryRecursively($capturedTempDir);
    }

    private function credential(string $publicUrl = 'https://mirror.tld/<id>/<version>/<file>'): MirrorCredential
    {
        return new MirrorCredential('mirror1', 'a.tld', 21, 'u', 'p', '/mirror', 'ftps', $publicUrl);
    }

    private static function removeDirectoryRecursively(string $dir): void
    {
        foreach (new \FilesystemIterator($dir) as $item) {
            $item->isDir() ? self::removeDirectoryRecursively($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
