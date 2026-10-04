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

namespace AnimeDb\Plugins\AnimedbMyanimelist\Tests\Mapping;

use AnimeDb\PluginContracts\Sync\SyncStatus;
use AnimeDb\Plugins\AnimedbMyanimelist\Mapping\SyncStatusMapper;
use PHPUnit\Framework\TestCase;

final class SyncStatusMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{SyncStatus, string}>
     */
    public static function toMalProvider(): iterable
    {
        yield 'plan' => [SyncStatus::Plan, 'plan_to_watch'];
        yield 'watching' => [SyncStatus::Watching, 'watching'];
        yield 'completed' => [SyncStatus::Completed, 'completed'];
        yield 'on hold' => [SyncStatus::OnHold, 'on_hold'];
        yield 'dropped' => [SyncStatus::Dropped, 'dropped'];
    }

    /**
     * @dataProvider toMalProvider
     */
    public function testToMalMapsEveryContractStatus(SyncStatus $status, string $expected): void
    {
        self::assertSame($expected, SyncStatusMapper::toMal($status));
    }

    /**
     * @return iterable<string, array{string, SyncStatus}>
     */
    public static function fromMalProvider(): iterable
    {
        yield 'plan_to_watch' => ['plan_to_watch', SyncStatus::Plan];
        yield 'watching' => ['watching', SyncStatus::Watching];
        yield 'completed' => ['completed', SyncStatus::Completed];
        yield 'on_hold' => ['on_hold', SyncStatus::OnHold];
        yield 'dropped' => ['dropped', SyncStatus::Dropped];
    }

    /**
     * @dataProvider fromMalProvider
     */
    public function testFromMalMapsEveryKnownStatus(string $malStatus, SyncStatus $expected): void
    {
        self::assertSame($expected, SyncStatusMapper::fromMal($malStatus, false));
    }

    public function testFromMalReturnsNullForUnknownStatus(): void
    {
        self::assertNull(SyncStatusMapper::fromMal('unknown_status', false));
    }

    public function testFromMalIsRewatchingTrueAlwaysMapsToWatchingRegardlessOfStatus(): void
    {
        self::assertSame(SyncStatus::Watching, SyncStatusMapper::fromMal('plan_to_watch', true));
        self::assertSame(SyncStatus::Watching, SyncStatusMapper::fromMal('completed', true));
        self::assertSame(SyncStatus::Watching, SyncStatusMapper::fromMal('unknown_status', true));
    }
}
