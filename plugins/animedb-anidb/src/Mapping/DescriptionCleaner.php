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

namespace AnimeDb\Plugins\AnimedbAnidb\Mapping;

/**
 * Turns AniDB's `description` markup into plain text.
 *
 * An inline link `http://anidb.net/cr123 [Name]` is replaced by its label, a trailing
 * `Source: …` block and any `Note: …` line are removed (editorial metadata, not part of the
 * description); text without them is left untouched. AniDB descriptions are English.
 */
final class DescriptionCleaner
{
    public const LANGUAGE = 'en';

    public static function clean(string $raw): string
    {
        $text = preg_replace('~https?://(?:[\w-]+\.)*anidb\.net/\S+\s+\[([^\]]*)\]~u', '$1', $raw) ?? $raw;
        $text = preg_replace('/^[ \t]*Note:.*(?:\R|\z)/mu', '', $text) ?? $text;
        $text = preg_replace('/(?:^|\R)[ \t]*Source(?:[ \t]+[^\r\n:]+)?:.*\z/su', '', $text) ?? $text;

        return trim($text);
    }
}
