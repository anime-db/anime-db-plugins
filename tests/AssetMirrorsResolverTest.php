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

use AnimeDb\Plugins\Tools\AssetMirrorsResolver;
use AnimeDb\Plugins\Tools\MirrorCredential;
use PHPUnit\Framework\TestCase;

final class AssetMirrorsResolverTest extends TestCase
{
    public function testEmptyActiveMirrorsYieldsGithubOnly(): void
    {
        $result = (new AssetMirrorsResolver())->resolve(['mirror1' => $this->credential('mirror1')], []);

        self::assertSame([AssetMirrorsResolver::GITHUB_MIRROR], $result['mirrors']);
        self::assertSame([], $result['warnings']);
    }

    public function testGithubIsAlwaysFirstFollowedByActiveMirrorsInGivenOrder(): void
    {
        $mirrors = [
            'mirror1' => $this->credential('mirror1', 'https://mirror1.example.org/<id>/<version>/<file>'),
            'second' => $this->credential('second', 'https://second.tld/<id>/<version>/<file>'),
        ];

        $result = (new AssetMirrorsResolver())->resolve($mirrors, ['mirror1', 'second']);

        self::assertSame(
            [
                AssetMirrorsResolver::GITHUB_MIRROR,
                'https://mirror1.example.org/<id>/<version>/<file>',
                'https://second.tld/<id>/<version>/<file>',
            ],
            $result['mirrors'],
        );
        self::assertSame([], $result['warnings']);
    }

    public function testInactiveMirrorIsExcludedEvenIfCredentialExists(): void
    {
        $mirrors = [
            'mirror1' => $this->credential('mirror1'),
            'not-active' => $this->credential('not-active'),
        ];

        $result = (new AssetMirrorsResolver())->resolve($mirrors, ['mirror1']);

        self::assertSame([AssetMirrorsResolver::GITHUB_MIRROR, $mirrors['mirror1']->publicUrl], $result['mirrors']);
    }

    public function testActiveMirrorWithoutMatchingCredentialIsSkippedWithWarning(): void
    {
        $result = (new AssetMirrorsResolver())->resolve([], ['mirror1']);

        self::assertSame([AssetMirrorsResolver::GITHUB_MIRROR], $result['mirrors']);
        self::assertCount(1, $result['warnings']);
        self::assertStringContainsString('mirror1', $result['warnings'][0]);
    }

    /**
     * @dataProvider provideInvalidPublicUrl
     */
    public function testInvalidPublicUrlIsSkippedWithWarningInsteadOfThrowing(string $publicUrl): void
    {
        $mirrors = ['mirror1' => $this->credential('mirror1', $publicUrl)];

        $result = (new AssetMirrorsResolver())->resolve($mirrors, ['mirror1']);

        self::assertSame([AssetMirrorsResolver::GITHUB_MIRROR], $result['mirrors']);
        self::assertCount(1, $result['warnings']);
        self::assertStringContainsString('mirror1', $result['warnings'][0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidPublicUrl(): iterable
    {
        yield 'not https' => ['http://mirror1.example.org/<id>/<version>/<file>'];
        yield 'missing macros' => ['https://mirror1.example.org/downloads'];
        yield 'not a url' => ['not-a-url'];
    }

    private function credential(string $id, string $publicUrl = 'https://example.tld/<id>/<version>/<file>'): MirrorCredential
    {
        return new MirrorCredential($id, 'ftp.example.tld', 21, 'u', 'p', '/mirror', 'ftps', $publicUrl);
    }
}
