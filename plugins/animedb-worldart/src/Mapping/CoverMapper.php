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

namespace AnimeDb\Plugins\AnimedbWorldart\Mapping;

use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;

/**
 * The cover is the `src` of the cover `<img>` of the card; a relative address is completed to the
 * site's host. The formula {@see self::fallback()} is only the fallback for a card without the
 * `<img>`: `ceil(id / 1000) * 1000` would be wrong on every id divisible by 1000.
 */
final class CoverMapper
{
    private const HOST = 'http://www.world-art.ru';

    public static function map(WorldartPage $page, int $id): ?string
    {
        $src = trim((string) $page->coverSrc());
        if ($src === '') {
            return self::fallback($id);
        }

        if (preg_match('~^https?://~i', $src) === 1) {
            $url = $src;
        } else {
            $url = self::HOST.'/'.ltrim($src, '/');
        }

        return UrlGuard::isSafe($url) ? $url : self::fallback($id);
    }

    public static function fallback(int $id): ?string
    {
        if ($id < 1) {
            return null;
        }

        return \sprintf('%s/animation/img/%d/%d/1.jpg', self::HOST, (intdiv($id, 1000) + 1) * 1000, $id);
    }
}
