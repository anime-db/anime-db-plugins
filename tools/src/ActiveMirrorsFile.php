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
 * Parses/serializes the git-tracked `active-mirrors` file: a plain-text list of mirror ids (one
 * per line, blank lines and `#`-comments ignored) that gates which `MIRROR_CREDS` entries get
 * advertised in `asset_mirrors` (see {@see AssetMirrorsResolver}).
 *
 * Being a plain reviewable git file (not part of the `MIRROR_CREDS` secret) is the point: what
 * mirrors clients are told about is a security-relevant decision that goes through normal PR
 * review, while the mirror's actual FTP coordinates and public URL template stay in the secret.
 * De-listing a banned/leaked mirror is "remove its id from this file", nothing more.
 */
final class ActiveMirrorsFile
{
    /**
     * Mirrors {@see MirrorCredentialsParser::MIRROR_ID_PATTERN} — the id here is meant to match a
     * `MIRROR_CREDS` key one-for-one.
     */
    private const MIRROR_ID_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*\z/';

    /**
     * @return list<string> sorted, de-duplicated mirror ids
     */
    public function parse(string $content): array
    {
        $ids = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match(self::MIRROR_ID_PATTERN, $line) !== 1) {
                throw new \RuntimeException(\sprintf('"%s" is not a valid mirror id in active-mirrors. It must be a lowercase, hyphen-separated slug (e.g. "reg-ru").', $line));
            }

            $ids[$line] = true;
        }

        $ids = array_keys($ids);
        sort($ids, \SORT_STRING);

        return $ids;
    }

    /**
     * @param list<string> $ids
     */
    public function serialize(array $ids): string
    {
        $ids = array_values(array_unique($ids));
        sort($ids, \SORT_STRING);

        return $ids === [] ? '' : implode("\n", $ids)."\n";
    }
}
