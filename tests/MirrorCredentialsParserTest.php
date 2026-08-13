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

use AnimeDb\Plugins\Tools\MirrorCredentialsParser;
use PHPUnit\Framework\TestCase;

final class MirrorCredentialsParserTest extends TestCase
{
    public function testParsesAllFieldsAndDefaultsProtocolToFtpsAndPortTo21(): void
    {
        $mirrors = (new MirrorCredentialsParser())->parse(json_encode([
            'mirror1' => [
                'host' => 'ftp.example.tld',
                'user' => 'mirror_user',
                'password' => 'secret',
                'dir' => '/public_html/mirror',
                'public_url' => 'https://mirror1.example.org/mirror/<id>/<version>/<file>',
            ],
        ], \JSON_THROW_ON_ERROR));

        self::assertCount(1, $mirrors);
        $mirror = $mirrors['mirror1'];
        self::assertSame('mirror1', $mirror->id);
        self::assertSame('ftp.example.tld', $mirror->host);
        self::assertSame(21, $mirror->port);
        self::assertSame('mirror_user', $mirror->user);
        self::assertSame('secret', $mirror->password);
        self::assertSame('/public_html/mirror', $mirror->dir);
        self::assertSame('ftps', $mirror->protocol);
        self::assertSame('https://mirror1.example.org/mirror/<id>/<version>/<file>', $mirror->publicUrl);
    }

    public function testExplicitPortAndFtpProtocolAreHonoured(): void
    {
        $mirrors = (new MirrorCredentialsParser())->parse(json_encode([
            'mirror1' => [
                'host' => 'ftp.example.tld',
                'port' => 2121,
                'user' => 'mirror_user',
                'password' => 'secret',
                'dir' => '/public_html/mirror',
                'protocol' => 'ftp',
                'public_url' => 'https://mirror1.example.org/mirror/<id>/<version>/<file>',
            ],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(2121, $mirrors['mirror1']->port);
        self::assertSame('ftp', $mirrors['mirror1']->protocol);
    }

    public function testMultipleMirrorsAreSortedByIdForADeterministicOrder(): void
    {
        $mirrors = (new MirrorCredentialsParser())->parse(json_encode([
            'zzz-mirror' => ['host' => 'z.tld', 'user' => 'u', 'password' => 'p', 'dir' => '/d', 'public_url' => 'https://z.tld/<id>/<version>/<file>'],
            'aaa-mirror' => ['host' => 'a.tld', 'user' => 'u', 'password' => 'p', 'dir' => '/d', 'public_url' => 'https://a.tld/<id>/<version>/<file>'],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(['aaa-mirror', 'zzz-mirror'], array_keys($mirrors));
    }

    public function testEmptyObjectYieldsNoMirrors(): void
    {
        self::assertSame([], (new MirrorCredentialsParser())->parse('{}'));
    }

    public function testMalformedJsonThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new MirrorCredentialsParser())->parse('not json');
    }

    public function testJsonArrayAtTopLevelThrows(): void
    {
        // A non-empty JSON array; an empty "[]" is indistinguishable from an empty "{}" once
        // decoded to a PHP array, and an empty object is the valid "no mirrors configured" case
        // (see testEmptyObjectYieldsNoMirrors).
        $this->expectException(\RuntimeException::class);

        (new MirrorCredentialsParser())->parse('["mirror1"]');
    }

    public function testInvalidMirrorIdThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new MirrorCredentialsParser())->parse(json_encode([
            'Reg_RU' => ['host' => 'a.tld', 'user' => 'u', 'password' => 'p', 'dir' => '/d', 'public_url' => 'https://a.tld/<id>/<version>/<file>'],
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @dataProvider provideMissingRequiredField
     */
    public function testMissingRequiredFieldThrows(string $field): void
    {
        $entry = ['host' => 'a.tld', 'user' => 'u', 'password' => 'p', 'dir' => '/d', 'public_url' => 'https://a.tld/<id>/<version>/<file>'];
        unset($entry[$field]);

        $this->expectException(\RuntimeException::class);

        (new MirrorCredentialsParser())->parse(json_encode(['mirror1' => $entry], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMissingRequiredField(): iterable
    {
        yield 'host' => ['host'];
        yield 'user' => ['user'];
        yield 'password' => ['password'];
        yield 'dir' => ['dir'];
        yield 'public_url' => ['public_url'];
    }

    /**
     * public_url is only required to be a non-empty string at parse time; whether it is a
     * well-formed "https://...<id>...<version>...<file>..." template is deliberately checked
     * later, by AssetMirrorsResolver, which fails open (drops the mirror with a warning) instead
     * of here, where it would hard-fail every tool that just needs the write credentials.
     */
    public function testMalformedPublicUrlDoesNotThrowAtParseTime(): void
    {
        $mirrors = (new MirrorCredentialsParser())->parse(json_encode([
            'mirror1' => ['host' => 'a.tld', 'user' => 'u', 'password' => 'p', 'dir' => '/d', 'public_url' => 'not-a-url'],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame('not-a-url', $mirrors['mirror1']->publicUrl);
    }

    public function testInvalidProtocolThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new MirrorCredentialsParser())->parse(json_encode([
            'mirror1' => ['host' => 'a.tld', 'user' => 'u', 'password' => 'p', 'dir' => '/d', 'protocol' => 'sftp', 'public_url' => 'https://a.tld/<id>/<version>/<file>'],
        ], \JSON_THROW_ON_ERROR));
    }

    public function testOutOfRangePortThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new MirrorCredentialsParser())->parse(json_encode([
            'mirror1' => ['host' => 'a.tld', 'user' => 'u', 'password' => 'p', 'dir' => '/d', 'port' => 0, 'public_url' => 'https://a.tld/<id>/<version>/<file>'],
        ], \JSON_THROW_ON_ERROR));
    }

    public function testRelativeDirThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new MirrorCredentialsParser())->parse(json_encode([
            'mirror1' => ['host' => 'a.tld', 'user' => 'u', 'password' => 'p', 'dir' => 'mirror', 'public_url' => 'https://a.tld/<id>/<version>/<file>'],
        ], \JSON_THROW_ON_ERROR));
    }
}
