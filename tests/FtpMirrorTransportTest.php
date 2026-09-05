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

use AnimeDb\Plugins\Tools\FtpMirrorTransport;
use AnimeDb\Plugins\Tools\MirrorCredential;
use PHPUnit\Framework\TestCase;

/**
 * Exercises {@see FtpMirrorTransport::connect()} against a real ext-ftp client talking to a fake
 * FTP control-connection server (see fixtures/fake-ftp-login-reject-server.php), since the bug
 * being guarded against — a connection left open after a failed `ftp_login()` — cannot be observed
 * through a fake {@see \AnimeDb\Plugins\Tools\MirrorTransport}: it is a property of the real
 * ext-ftp resource, not of this class's own state.
 */
final class FtpMirrorTransportTest extends TestCase
{
    public function testAuthenticationFailureClosesTheConnection(): void
    {
        $resultFile = sys_get_temp_dir().'/ftp-mirror-transport-close-'.bin2hex(random_bytes(8));

        $server = proc_open(
            [\PHP_BINARY, \dirname(__DIR__).'/tests/fixtures/fake-ftp-login-reject-server.php', $resultFile, '2'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($server);

        $port = self::readAnnouncedPort($pipes[1]);

        $credential = new MirrorCredential('mirror1', '127.0.0.1', $port, 'u', 'wrong-password', '/mirror', 'ftp', 'https://mirror.tld/<id>/<version>/<file>');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to authenticate to mirror "mirror1".');
            (new FtpMirrorTransport())->uploadFile($credential, __FILE__, '/plugin.zip');
        } finally {
            proc_close($server);
            self::assertSame('CLOSED', is_file($resultFile) ? file_get_contents($resultFile) : 'NOT_CLOSED');
            if (is_file($resultFile)) {
                unlink($resultFile);
            }
        }
    }

    /**
     * @param resource $stdout
     */
    private static function readAnnouncedPort($stdout): int
    {
        $line = fgets($stdout);
        if ($line === false || !str_starts_with($line, 'LISTENING ')) {
            self::fail('Fake FTP server did not announce its listening port.');
        }

        return (int) substr($line, \strlen('LISTENING '));
    }
}
