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
 * Parses the `MIRROR_CREDS` GitHub Actions secret — a single JSON object keyed by mirror id,
 * each value carrying that mirror's write credentials — into {@see MirrorCredential} objects.
 *
 * Keeping every mirror's credentials in one structured secret, rather than one secret per mirror
 * (`FTP_HOST_1`, `FTP_HOST_2`, ...), is deliberate: adding a mirror becomes "append a key to this
 * JSON", not "provision a new GitHub secret", and the number of secrets stays constant as the
 * mirror list grows. See issue #14.
 */
final class MirrorCredentialsParser
{
    private const DEFAULT_PORT = 21;

    private const VALID_PROTOCOLS = ['ftp', 'ftps'];

    /**
     * Mirror id shape: not used as a path segment anywhere, but kept identifier-safe so it reads
     * cleanly in logs and errors, matching the vendor-name-ish slugs used for plugin ids
     * elsewhere in this repo (e.g. "reg-ru").
     */
    private const MIRROR_ID_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*\z/';

    /**
     * @return array<string, MirrorCredential> keyed by mirror id, sorted by id so the publish
     *                                         order is deterministic and stable across CI runs
     */
    public function parse(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(\sprintf('MIRROR_CREDS is not valid JSON: %s', $exception->getMessage()));
        }

        if (!\is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new \RuntimeException('MIRROR_CREDS must contain a JSON object keyed by mirror id.');
        }

        $mirrors = [];
        foreach ($decoded as $id => $entry) {
            $id = (string) $id;

            if (preg_match(self::MIRROR_ID_PATTERN, $id) !== 1) {
                throw new \RuntimeException(\sprintf('"%s" is not a valid mirror id. It must be a lowercase, hyphen-separated slug (e.g. "reg-ru").', $id));
            }

            if (!\is_array($entry)) {
                throw new \RuntimeException(\sprintf('MIRROR_CREDS entry "%s" must be a JSON object.', $id));
            }

            foreach (['host', 'user', 'password', 'dir'] as $field) {
                if (!\is_string($entry[$field] ?? null) || $entry[$field] === '') {
                    throw new \RuntimeException(\sprintf('MIRROR_CREDS entry "%s" is missing a non-empty "%s" string field.', $id, $field));
                }
            }

            // Require an absolute dir: FtpMirrorTransport creates directories from the FTP root
            // (leading "/") while ftp_put() uses the path as given, so a relative dir would create
            // "/mirror/..." but upload to "mirror/..." (relative to the login home) — a silent
            // mismatch. Fail fast with a clear message instead.
            if ($entry['dir'][0] !== '/') {
                throw new \RuntimeException(\sprintf('MIRROR_CREDS entry "%s" has a "dir" that must be an absolute path starting with "/".', $id));
            }

            $protocol = $entry['protocol'] ?? 'ftps';
            if (!\is_string($protocol) || !\in_array($protocol, self::VALID_PROTOCOLS, true)) {
                throw new \RuntimeException(\sprintf('MIRROR_CREDS entry "%s" has an invalid "protocol"; expected one of: %s.', $id, implode(', ', self::VALID_PROTOCOLS)));
            }

            $port = $entry['port'] ?? self::DEFAULT_PORT;
            if (!\is_int($port) || $port < 1 || $port > 65535) {
                throw new \RuntimeException(\sprintf('MIRROR_CREDS entry "%s" has an invalid "port"; expected an integer between 1 and 65535.', $id));
            }

            $mirrors[$id] = new MirrorCredential(
                id: $id,
                host: $entry['host'],
                port: $port,
                user: $entry['user'],
                password: $entry['password'],
                dir: $entry['dir'],
                protocol: $protocol,
            );
        }

        ksort($mirrors, \SORT_STRING);

        return $mirrors;
    }
}
