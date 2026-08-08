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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Mapping;

use AnimeDb\PluginContracts\Sync\SyncStatus;
use AnimeDb\Plugins\AnimedbShikimori\Mapping\SyncStatusMapper;
use PHPUnit\Framework\TestCase;

final class SyncStatusMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{SyncStatus, string}>
     */
    public static function toShikimoriProvider(): iterable
    {
        yield 'plan' => [SyncStatus::Plan, 'planned'];
        yield 'watching' => [SyncStatus::Watching, 'watching'];
        yield 'completed' => [SyncStatus::Completed, 'completed'];
        yield 'on hold' => [SyncStatus::OnHold, 'on_hold'];
        yield 'dropped' => [SyncStatus::Dropped, 'dropped'];
    }

    /**
     * @dataProvider toShikimoriProvider
     */
    public function testToShikimoriMapsEveryContractStatus(SyncStatus $status, string $expected): void
    {
        self::assertSame($expected, SyncStatusMapper::toShikimori($status));
    }

    /**
     * @return iterable<string, array{string, SyncStatus}>
     */
    public static function fromShikimoriProvider(): iterable
    {
        yield 'planned' => ['planned', SyncStatus::Plan];
        yield 'watching' => ['watching', SyncStatus::Watching];
        yield 'rewatching folds into watching' => ['rewatching', SyncStatus::Watching];
        yield 'completed' => ['completed', SyncStatus::Completed];
        yield 'on_hold' => ['on_hold', SyncStatus::OnHold];
        yield 'dropped' => ['dropped', SyncStatus::Dropped];
    }

    /**
     * @dataProvider fromShikimoriProvider
     */
    public function testFromShikimoriMapsEveryKnownStatus(string $shikimoriStatus, SyncStatus $expected): void
    {
        self::assertSame($expected, SyncStatusMapper::fromShikimori($shikimoriStatus));
    }

    public function testFromShikimoriReturnsNullForUnknownStatus(): void
    {
        self::assertNull(SyncStatusMapper::fromShikimori('unknown_status'));
    }
}
