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

use AnimeDb\Plugins\Tools\MirrorCredential;
use AnimeDb\Plugins\Tools\MirrorRegistryPublisher;
use PHPUnit\Framework\TestCase;

final class MirrorRegistryPublisherTest extends TestCase
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

    public function testUploadsRegistryThenSignatureToRootOfEveryMirror(): void
    {
        [$registry, $signature] = $this->makeRegistryFiles();
        $transport = new FakeMirrorTransport();

        $mirrors = [
            'reg-ru' => new MirrorCredential('reg-ru', 'a.tld', 21, 'u', 'p', '/public_html/mirror', 'ftps', 'https://example.tld/<id>/<version>/<file>'),
            'second' => new MirrorCredential('second', 'b.tld', 21, 'u', 'p', '/srv/mirror/', 'ftp', 'https://example.tld/<id>/<version>/<file>'),
        ];

        (new MirrorRegistryPublisher($transport))->publish($mirrors, $registry, $signature);

        // Registry before signature, per mirror, into each mirror's own dir root.
        self::assertSame(
            [
                '/public_html/mirror/plugins-registry.json',
                '/public_html/mirror/plugins-registry.json.sig',
                '/srv/mirror/plugins-registry.json',
                '/srv/mirror/plugins-registry.json.sig',
            ],
            $transport->uploaded,
        );
    }

    public function testRePublishUploadsEveryTimeRegistryIsMutable(): void
    {
        [$registry, $signature] = $this->makeRegistryFiles();
        $transport = new FakeMirrorTransport();

        $mirrors = [
            'reg-ru' => new MirrorCredential('reg-ru', 'a.tld', 21, 'u', 'p', '/public_html/mirror', 'ftps', 'https://example.tld/<id>/<version>/<file>'),
        ];

        // The registry is mutable (changes every release): each publish re-uploads both files,
        // overwriting the previous copy — no skip-if-present.
        $publisher = new MirrorRegistryPublisher($transport);
        $publisher->publish($mirrors, $registry, $signature);
        $publisher->publish($mirrors, $registry, $signature);

        self::assertCount(4, $transport->uploaded);
    }

    public function testRejectsMissingRegistryFile(): void
    {
        [, $signature] = $this->makeRegistryFiles();

        $this->expectException(\RuntimeException::class);

        (new MirrorRegistryPublisher(new FakeMirrorTransport()))->publish(
            ['reg-ru' => new MirrorCredential('reg-ru', 'a.tld', 21, 'u', 'p', '/m', 'ftps', 'https://example.tld/<id>/<version>/<file>')],
            $this->tempDir.'/does-not-exist.json',
            $signature,
        );
    }

    /**
     * @return array{string, string} paths to a registry file and its signature file
     */
    private function makeRegistryFiles(): array
    {
        $this->tempDir = sys_get_temp_dir().'/mirror-registry-'.uniqid('', true);
        mkdir($this->tempDir);
        $registry = $this->tempDir.'/plugins-registry.json';
        $signature = $this->tempDir.'/plugins-registry.json.sig';
        file_put_contents($registry, '{"sequence":1,"plugins":[]}');
        file_put_contents($signature, 'signature-bytes');

        return [$registry, $signature];
    }
}
