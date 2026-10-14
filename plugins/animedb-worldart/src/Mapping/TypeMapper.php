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

use AnimeDb\PluginContracts\Model\AnimeType;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;

/**
 * The "Тип" label: `ТВ (28 эп.), 25 мин.`, `Web (10 эп.), 5 мин.`, `ТВ (11 эп. + спэшл), 4 мин.`,
 * `полнометражный фильм, 90 мин.`. The kind is the text before the first `(` or `,`; the episode
 * count and the duration are read on their own, so an unknown kind does not blank them. The
 * `+ спэшл` part is dropped; a film without a number has one episode.
 */
final class TypeMapper
{
    /** @var array<string, AnimeType> */
    private const TYPES = [
        'тв' => AnimeType::Tv,
        'тв-спэшл' => AnimeType::Special,
        'ova' => AnimeType::Ova,
        'oav' => AnimeType::Ova,
        'ona' => AnimeType::Ona,
        'web' => AnimeType::Ona,
        'полнометражный фильм' => AnimeType::Movie,
        'короткометражный фильм' => AnimeType::Movie,
        'музыкальное видео' => AnimeType::Music,
    ];

    public static function type(WorldartPage $page): ?AnimeType
    {
        $value = self::value($page);
        if ($value === null) {
            return null;
        }
        $kind = mb_strtolower(trim((string) preg_replace('/[(,].*$/su', '', $value)));

        return self::TYPES[$kind] ?? null;
    }

    public static function episodesCount(WorldartPage $page): ?int
    {
        $value = self::value($page);
        if ($value !== null && preg_match('/\(\s*(\d{1,5})\s*эп\./u', $value, $matches) === 1 && (int) $matches[1] > 0) {
            return (int) $matches[1];
        }

        return self::type($page) === AnimeType::Movie ? 1 : null;
    }

    public static function durationMinutes(WorldartPage $page): ?int
    {
        $value = self::value($page);
        if ($value !== null && preg_match('/,\s*(\d{1,4})\s*мин\./u', $value, $matches) === 1 && (int) $matches[1] > 0) {
            return (int) $matches[1];
        }

        return null;
    }

    private static function value(WorldartPage $page): ?string
    {
        $node = $page->valueNode('Тип');

        return $node === null ? null : WorldartPage::clean((string) $node->textContent);
    }
}
