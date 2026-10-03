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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Mapping;

/**
 * Strips MyAnimeList's trailing attribution notices out of `synopsis`, leaving plain text.
 *
 * MyAnimeList appends a provenance notice at the end of a synopsis — either `[Written by MAL
 * Rewrite]` or `(Source: ...)` — that is editorial metadata about the text, not part of the
 * synopsis itself, and must not appear in {@see \AnimeDb\PluginContracts\Filler\PluginAnimeData::$descriptions}.
 * Both notices are stripped only from the end of the text, repeatedly, so a synopsis ending
 * with one right after the other loses both; the rest of the text is left untouched.
 */
final class SynopsisCleaner
{
    /** @var list<string> */
    private const TRAILING_NOTICE_PATTERNS = [
        '/\s*\[Written by MAL Rewrite\]\s*$/',
        '/\s*\(Source:(?:[^()]|\([^()]*\))*\)\s*$/',
    ];

    public static function clean(string $raw): string
    {
        $text = $raw;

        do {
            $previous = $text;
            foreach (self::TRAILING_NOTICE_PATTERNS as $pattern) {
                $text = preg_replace($pattern, '', $text) ?? $text;
            }
        } while ($text !== $previous);

        return $text;
    }
}
