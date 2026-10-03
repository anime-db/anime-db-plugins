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

use AnimeDb\PluginContracts\Sync\SyncStatus;

/**
 * Maps between the contract's {@see SyncStatus} and MyAnimeList's `my_list_status.status`
 * vocabulary (`plan_to_watch, watching, completed, on_hold, dropped`, per the MyAnimeList API
 * v2 reference).
 *
 * Unlike Shikimori, MyAnimeList does not have a `rewatching` status value — rewatching a title
 * is instead a separate boolean field, `is_rewatching`, that can accompany any `status`.
 * {@see self::fromMal()} therefore takes both: when `$isRewatching` is `true`, it always
 * returns {@see SyncStatus::Watching}, regardless of `$status` — mirroring
 * {@see \AnimeDb\Plugins\AnimedbShikimori\Mapping\SyncStatusMapper::fromShikimori()}'s folding
 * of `rewatching` into {@see SyncStatus::Watching}, just keyed off a different field. The
 * reverse direction ({@see self::toMal()}) never produces `is_rewatching: true`: the contract
 * has no status a push could map it from, so a write always sends `is_rewatching: false` (see
 * {@see \AnimeDb\Plugins\AnimedbMyanimelist\Http\MalApiClient::updateListStatus()}).
 */
final class SyncStatusMapper
{
    private const TO_MAL = [
        SyncStatus::Plan->value => 'plan_to_watch',
        SyncStatus::Watching->value => 'watching',
        SyncStatus::Completed->value => 'completed',
        SyncStatus::OnHold->value => 'on_hold',
        SyncStatus::Dropped->value => 'dropped',
    ];

    private const FROM_MAL = [
        'plan_to_watch' => SyncStatus::Plan,
        'watching' => SyncStatus::Watching,
        'completed' => SyncStatus::Completed,
        'on_hold' => SyncStatus::OnHold,
        'dropped' => SyncStatus::Dropped,
    ];

    private function __construct()
    {
    }

    public static function toMal(SyncStatus $status): string
    {
        return self::TO_MAL[$status->value];
    }

    /**
     * @return SyncStatus|null null for a MyAnimeList status outside the known vocabulary
     *                        (and `$isRewatching` is not `true`)
     */
    public static function fromMal(string $status, bool $isRewatching): ?SyncStatus
    {
        if ($isRewatching) {
            return SyncStatus::Watching;
        }

        return self::FROM_MAL[$status] ?? null;
    }
}
