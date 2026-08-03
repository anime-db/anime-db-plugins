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
            'reg-ru' => new MirrorCredential('reg-ru', 'a.tld', 21, 'u', 'p', '/public_html/mirror', 'ftps'),
            'second' => new MirrorCredential('second', 'b.tld', 21, 'u', 'p', '/srv/mirror/', 'ftp'),
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

    public function testAlreadyPublishedFilesAreNeverReUploadedIdempotency(): void
    {
        $assetsDir = $this->makeAssetsDir();
        $transport = new FakeMirrorTransport();
        $transport->existing[] = '/public_html/mirror/animedb-shikimori/0.2.0/plugin.zip';
        $transport->existing[] = '/public_html/mirror/animedb-shikimori/0.2.0/manifest.json';

        $mirrors = [
            'reg-ru' => new MirrorCredential('reg-ru', 'a.tld', 21, 'u', 'p', '/public_html/mirror', 'ftps'),
        ];

        (new MirrorAssetPublisher($transport))->publish(
            $mirrors,
            'animedb-shikimori',
            '0.2.0',
            $assetsDir,
            ['plugin.zip', 'manifest.json'],
        );

        self::assertSame([], $transport->uploaded);
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
            'reg-ru' => new MirrorCredential('reg-ru', 'a.tld', 21, 'u', 'p', '/mirror', 'ftps'),
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
