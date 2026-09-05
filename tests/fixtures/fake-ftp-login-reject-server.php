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

/*
 * Minimal FTP control-connection server, used by FtpMirrorTransportTest to exercise
 * FtpMirrorTransport::connect() against a real ext-ftp client without any external network.
 * Speaks just enough of the protocol to reject a login (USER -> 331, PASS -> 530), then waits
 * specifically for QUIT to decide whether the caller released the connection through ext-ftp's own
 * close call, and writes "CLOSED"/"NOT_CLOSED" to the result file. A plain socket disconnect that
 * never sent QUIT (e.g. the connection object merely falling out of scope and getting garbage
 * collected, without an explicit close call) is reported as "NOT_CLOSED": only ext-ftp's close
 * function sends QUIT before dropping the socket, so requiring it is what makes this fixture able to
 * tell "closed via ftp_close()" apart from "closed some other way".
 *
 * Binds to an OS-assigned port (avoids colliding with an unrelated service on a guessed port) and
 * announces it on stdout once listening, so the caller never has to probe the socket itself: a probe
 * connection would consume the single `stream_socket_accept()` this fixture serves, starving the
 * real FTP client that is supposed to receive it.
 *
 * Usage: php fake-ftp-login-reject-server.php <result-file> <close-wait-seconds>
 */

[, $resultFile, $closeWaitArg] = $argv;
$closeWaitSeconds = (float) $closeWaitArg;

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(\STDERR, "Failed to listen: {$errstr}\n");
    exit(1);
}

$address = stream_socket_get_name($server, false);
$port = (int) substr($address, strrpos($address, ':') + 1);
fwrite(\STDOUT, "LISTENING {$port}\n");
fflush(\STDOUT);

$connection = stream_socket_accept($server, 30);
if ($connection === false) {
    fwrite(\STDERR, "No client connected.\n");
    exit(1);
}

fwrite($connection, "220 fake ftp ready\r\n");

stream_set_timeout($connection, (int) $closeWaitSeconds, (int) (($closeWaitSeconds - (int) $closeWaitSeconds) * 1_000_000));

$buffer = '';
$closed = false;
while (!$closed) {
    $chunk = fread($connection, 4096);
    if ($chunk === '' || $chunk === false) {
        // A disconnect without QUIT (timed out, or the peer just dropped the socket) is not the
        // graceful close ftp_close() performs: leave $closed false so the result is "NOT_CLOSED".
        break;
    }

    $buffer .= $chunk;
    while (($eol = strpos($buffer, "\r\n")) !== false) {
        $line = substr($buffer, 0, $eol);
        $buffer = substr($buffer, $eol + 2);

        if (stripos($line, 'USER') === 0) {
            fwrite($connection, "331 Please specify the password.\r\n");
        } elseif (stripos($line, 'PASS') === 0) {
            fwrite($connection, "530 Login incorrect.\r\n");
        } elseif (stripos($line, 'QUIT') === 0) {
            $closed = true;
        } else {
            fwrite($connection, "502 Command not implemented.\r\n");
        }
    }
}

file_put_contents($resultFile, $closed ? 'CLOSED' : 'NOT_CLOSED');
fclose($connection);
