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
use AnimeDb\Plugins\Tools\MirrorCredential;
use PHPUnit\Framework\TestCase;

final class MirrorAssetPublisherTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            foreach (glob($this->tempDir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
            $this->tempDir = null;
        }
    }

    public function testUploadsMissingFilesToTheVersionImmutablePathOnEveryMirror(): void
    {
        $assetsDir = $this->makeAssetsDir();
        $transport = new FakeMirrorTransport();

        $mirrors = [
            'mirror1' => new MirrorCredential('mirror1', 'a.tld', 21, 'u', 'p', '/public_html/mirror', 'ftps', 'https://example.tld/<id>/<version>/<file>'),
            'second' => new MirrorCredential('second', 'b.tld', 21, 'u', 'p', '/srv/mirror/', 'ftp', 'https://example.tld/<id>/<version>/<file>'),
        ];

        (new MirrorAssetPublisher($transport))->publish(
            $mirrors,
            'animedb-shikimori',
            '0.2.0',
            $assetsDir,
            ['plugin.zip', 'manifest.json'],
        );

        self::assertSame(
            [
                '/public_html/mirror/animedb-shikimori/0.2.0/plugin.zip',
                '/public_html/mirror/animedb-shikimori/0.2.0/manifest.json',
                '/srv/mirror/animedb-shikimori/0.2.0/plugin.zip',
                '/srv/mirror/animedb-shikimori/0.2.0/manifest.json',
            ],
            $transport->uploaded,
        );
    }

    public function testRePublishOverwritesRatherThanSkips(): void
    {
        $assetsDir = $this->makeAssetsDir();
        $transport = new FakeMirrorTransport();

        $mirrors = [
            'mirror1' => new MirrorCredential('mirror1', 'a.tld', 21, 'u', 'p', '/public_html/mirror', 'ftps', 'https://example.tld/<id>/<version>/<file>'),
        ];

        $publisher = new MirrorAssetPublisher($transport);
        $publisher->publish($mirrors, 'animedb-shikimori', '0.2.0', $assetsDir, ['plugin.zip', 'manifest.json']);
        $publisher->publish($mirrors, 'animedb-shikimori', '0.2.0', $assetsDir, ['plugin.zip', 'manifest.json']);

        // Overwrite, not skip: a second publish re-uploads both files (self-heals a remote file
        // truncated by an interrupted upload), rather than treating them as already-done.
        self::assertCount(4, $transport->uploaded);
    }

    public function testRejectsInvalidPluginId(): void
    {
        $assetsDir = $this->makeAssetsDir();

        $this->expectException(\RuntimeException::class);

        (new MirrorAssetPublisher(new FakeMirrorTransport()))->publish(
            [],
            '../../etc',
            '0.2.0',
            $assetsDir,
            ['plugin.zip'],
        );
    }

    public function testRejectsInvalidVersion(): void
    {
        $assetsDir = $this->makeAssetsDir();

        $this->expectException(\RuntimeException::class);

        (new MirrorAssetPublisher(new FakeMirrorTransport()))->publish(
            [],
            'animedb-shikimori',
            '../0.2.0',
            $assetsDir,
            ['plugin.zip'],
        );
    }

    public function testMissingLocalAssetThrowsBeforeAnyUpload(): void
    {
        $assetsDir = $this->makeAssetsDir();
        $transport = new FakeMirrorTransport();

        $mirrors = [
            'mirror1' => new MirrorCredential('mirror1', 'a.tld', 21, 'u', 'p', '/mirror', 'ftps', 'https://example.tld/<id>/<version>/<file>'),
        ];

        $this->expectException(\RuntimeException::class);

        try {
            (new MirrorAssetPublisher($transport))->publish(
                $mirrors,
                'animedb-shikimori',
                '0.2.0',
                $assetsDir,
                ['plugin.zip', 'does-not-exist.json'],
            );
        } finally {
            self::assertSame([], $transport->uploaded);
        }
    }

    public function testNoMirrorsIsANoOp(): void
    {
        $assetsDir = $this->makeAssetsDir();
        $transport = new FakeMirrorTransport();

        (new MirrorAssetPublisher($transport))->publish(
            [],
            'animedb-shikimori',
            '0.2.0',
            $assetsDir,
            ['plugin.zip', 'manifest.json'],
        );

        self::assertSame([], $transport->uploaded);
    }

    private function makeAssetsDir(): string
    {
        $this->tempDir = sys_get_temp_dir().'/mirror-asset-publisher-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir);
        file_put_contents($this->tempDir.'/plugin.zip', 'zip-bytes');
        file_put_contents($this->tempDir.'/manifest.json', '{"id":"animedb-shikimori"}');

        return $this->tempDir;
    }
}
