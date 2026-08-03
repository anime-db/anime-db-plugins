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

namespace AnimeDb\Plugins\Tools;

/**
 * Real FTP/FTPS transport, backed by PHP's ext-ftp. FTPS is used whenever a mirror's credential
 * says `protocol: ftps` (the preferred, encrypted option); plain `ftp` is a fallback for shared
 * hosting that does not offer FTPS. A fresh connection is opened per call — mirror pushes happen
 * a handful of times per release, not in a hot loop, so the extra round-trips are not worth the
 * complexity of connection pooling.
 */
final class FtpMirrorTransport implements MirrorTransport
{
    public function fileExists(MirrorCredential $credential, string $remotePath): bool
    {
        $connection = $this->connect($credential);

        try {
            // ftp_size() returns -1 both for "does not exist" and for servers that don't support
            // the SIZE command; treating -1 as "does not exist" is safe here because the worst
            // case is a redundant re-upload of an already-existing, byte-identical asset — never
            // a skipped upload of a missing one.
            return ftp_size($connection, $remotePath) >= 0;
        } finally {
            ftp_close($connection);
        }
    }

    public function uploadFile(MirrorCredential $credential, string $localPath, string $remotePath): void
    {
        $connection = $this->connect($credential);

        try {
            $this->ensureDirectoryExists($connection, \dirname($remotePath));

            if (!ftp_put($connection, $remotePath, $localPath, \FTP_BINARY)) {
                throw new \RuntimeException(\sprintf('Failed to upload "%s" to "%s" on mirror "%s".', $localPath, $remotePath, $credential->id));
            }
        } finally {
            ftp_close($connection);
        }
    }

    private function connect(MirrorCredential $credential): \FTP\Connection
    {
        $connection = $credential->protocol === 'ftps'
            ? ftp_ssl_connect($credential->host, $credential->port)
            : ftp_connect($credential->host, $credential->port);

        if ($connection === false) {
            throw new \RuntimeException(\sprintf('Failed to connect to mirror "%s" (%s:%d).', $credential->id, $credential->host, $credential->port));
        }

        if (!ftp_login($connection, $credential->user, $credential->password)) {
            throw new \RuntimeException(\sprintf('Failed to authenticate to mirror "%s".', $credential->id));
        }

        ftp_pasv($connection, true);

        return $connection;
    }

    private function ensureDirectoryExists(\FTP\Connection $connection, string $remoteDir): void
    {
        $segments = array_filter(explode('/', $remoteDir), static fn (string $segment): bool => $segment !== '');

        $path = '';
        foreach ($segments as $segment) {
            $path .= '/'.$segment;
            // @ftp_chdir()/@ftp_mkdir(): ext-ftp has no "mkdir -p" and no clean way to tell
            // "already exists" apart from other failures other than probing first — suppress the
            // expected warning for the common (already-there) case instead of paying an extra
            // round-trip per segment to check beforehand.
            if (@ftp_chdir($connection, $path) === false && @ftp_mkdir($connection, $path) === false) {
                throw new \RuntimeException(\sprintf('Failed to create directory "%s".', $path));
            }
        }
    }
}
