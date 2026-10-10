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

use AnimeDb\PluginContracts\Model\AnimeType;

/**
 * Maps AniDB's `type` to the contract's {@see AnimeType}.
 *
 * `Other`, `Unknown` and any value not listed below have no contract counterpart and
 * deliberately map to `null` — the caller drops the field rather than picking an inaccurate
 * stand-in.
 */
final class AnimeTypeMapper
{
    /** @var array<string, AnimeType> */
    private const MAP = [
        'TV Series' => AnimeType::Tv,
        'Movie' => AnimeType::Movie,
        'OVA' => AnimeType::Ova,
        'Web' => AnimeType::Ona,
        'TV Special' => AnimeType::Special,
        'Music Video' => AnimeType::Music,
    ];

    public static function map(?string $type): ?AnimeType
    {
        return $type !== null ? self::MAP[trim($type)] ?? null : null;
    }
}
