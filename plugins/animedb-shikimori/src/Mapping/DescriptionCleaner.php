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

namespace AnimeDb\Plugins\AnimedbShikimori\Mapping;

/**
 * Strips Shikimori's wiki/bb-style markup out of `Anime.description`, leaving plain text.
 *
 * Shikimori descriptions carry markup such as `[character=7407]Девятихвостый лис[/character]`,
 * `[[target|text]]`/`[[text]]` wiki links, and single bracketed annotations without a pair
 * (`[波風ミナト]`). None of it survives into {@see \AnimeDb\PluginContracts\Filler\PluginAnimeData::$descriptions},
 * which is meant to hold readable text, not source markup.
 */
final class DescriptionCleaner
{
    public static function clean(string $raw): string
    {
        $text = self::unwrapWikiLinks($raw);
        $text = self::unwrapPairedTags($text);
        $text = self::stripDanglingTagMarkers($text);
        $text = self::stripUnpairedAnnotations($text);

        return self::collapseWhitespace($text);
    }

    /**
     * `[[target|text]]` → `text`, `[[text]]` → `text`.
     */
    private static function unwrapWikiLinks(string $text): string
    {
        $text = preg_replace('/\[\[([^\]|]*)\|([^\]]*)\]\]/', '$2', $text) ?? $text;

        return preg_replace('/\[\[([^\]]*)\]\]/', '$1', $text) ?? $text;
    }

    /**
     * `[tag=..]text[/tag]` → `text`, repeated until stable so nested pairs (of any depth, in
     * any order) all get unwrapped — a single pass only resolves the outermost pair, since the
     * backreference ties each open tag to its own matching close and lazily skips over any
     * differently-named tags nested inside it.
     */
    private static function unwrapPairedTags(string $text): string
    {
        do {
            $next = preg_replace('/\[([a-zA-Z][a-zA-Z0-9_-]*)(?:=[^\]]*)?\](.*?)\[\/\1\]/s', '$2', $text, -1, $count);
            $text = $next ?? $text;
        } while ($count > 0);

        return $text;
    }

    /**
     * Removes leftover bbcode-like markers that never had (or lost, being unclosed) a pair —
     * `[spoiler]`, `[/spoiler]`, `[url=..]` — keeping whatever plain text surrounded them.
     */
    private static function stripDanglingTagMarkers(string $text): string
    {
        return preg_replace('/\[\/?[a-zA-Z][a-zA-Z0-9_-]*(?:=[^\]]*)?\]/', '', $text) ?? $text;
    }

    /**
     * Removes single-bracket annotations that are not bbcode-like tags — e.g. `[波風ミナト]` —
     * entirely, brackets and content alike.
     */
    private static function stripUnpairedAnnotations(string $text): string
    {
        return preg_replace('/\[[^\]]*\]/', '', $text) ?? $text;
    }

    private static function collapseWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
