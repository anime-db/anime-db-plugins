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

namespace AnimeDb\Plugins\AnimedbAnidb\Index;

/**
 * Normalizes both dump titles and search queries to the same form: lower-cased, every run of
 * characters that is neither a letter nor a digit collapsed into a single space, trimmed.
 */
final class TitleNormalizer
{
    private function __construct()
    {
    }

    public static function normalize(string $title): string
    {
        $lower = mb_strtolower($title);
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lower);

        return trim($clean ?? $lower);
    }
}
