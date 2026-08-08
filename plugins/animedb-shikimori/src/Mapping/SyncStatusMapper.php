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

namespace AnimeDb\Plugins\AnimedbShikimori\Mapping;

use AnimeDb\PluginContracts\Sync\SyncStatus;

/**
 * Maps between the contract's {@see SyncStatus} and Shikimori's `UserRateStatusEnum`
 * (`planned, watching, rewatching, completed, on_hold, dropped`, confirmed via live GraphQL
 * introspection against `shikimori.io` on 2026-08-08).
 *
 * The two vocabularies are not symmetric: Shikimori has `rewatching`, the contract does not.
 * {@see self::fromShikimori()} folds it into {@see SyncStatus::Watching} — a title being
 * rewatched is still, from the host application's point of view, being watched. The reverse
 * direction ({@see self::toShikimori()}) therefore never produces `rewatching`; there is no
 * contract status a push could map it from.
 */
final class SyncStatusMapper
{
    private const TO_SHIKIMORI = [
        SyncStatus::Plan->value => 'planned',
        SyncStatus::Watching->value => 'watching',
        SyncStatus::Completed->value => 'completed',
        SyncStatus::OnHold->value => 'on_hold',
        SyncStatus::Dropped->value => 'dropped',
    ];

    private const FROM_SHIKIMORI = [
        'planned' => SyncStatus::Plan,
        'watching' => SyncStatus::Watching,
        'rewatching' => SyncStatus::Watching,
        'completed' => SyncStatus::Completed,
        'on_hold' => SyncStatus::OnHold,
        'dropped' => SyncStatus::Dropped,
    ];

    private function __construct()
    {
    }

    public static function toShikimori(SyncStatus $status): string
    {
        return self::TO_SHIKIMORI[$status->value];
    }

    /**
     * @return SyncStatus|null null for a Shikimori status outside the known vocabulary
     */
    public static function fromShikimori(string $status): ?SyncStatus
    {
        return self::FROM_SHIKIMORI[$status] ?? null;
    }
}
