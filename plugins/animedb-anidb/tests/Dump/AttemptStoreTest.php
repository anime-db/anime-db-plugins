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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\Dump;

use AnimeDb\Plugins\AnimedbAnidb\Dump\AttemptStore;
use AnimeDb\Plugins\AnimedbAnidb\Tests\Support\ArraySettingsStore;
use PHPUnit\Framework\TestCase;

final class AttemptStoreTest extends TestCase
{
    public function testNoRecordIsDue(): void
    {
        self::assertTrue((new AttemptStore(new ArraySettingsStore()))->isDue(1000));
    }

    public function testGarbageRecordIsDue(): void
    {
        $settings = new ArraySettingsStore();
        $settings->data['dump_attempt'] = 'garbage';

        self::assertTrue((new AttemptStore($settings))->isDue(1000));
    }

    public function testRecordRetriesOnConcurrentWriteAndKeepsOtherSettings(): void
    {
        $settings = new ArraySettingsStore();
        $settings->data = ['other' => 'kept'];
        $settings->failNextUpdates = 2;

        (new AttemptStore($settings))->record(AttemptStore::KIND_RESPONSE, 500);

        self::assertSame(3, $settings->updateCalls);
        self::assertSame(['other' => 'kept', 'dump_attempt' => ['at' => 500, 'kind' => 'response']], $settings->data);
    }

    public function testRecordGivesUpAfterRepeatedConflicts(): void
    {
        $settings = new ArraySettingsStore();
        $settings->failNextUpdates = 100;

        (new AttemptStore($settings))->record(AttemptStore::KIND_RESPONSE, 500);

        self::assertSame(5, $settings->updateCalls);
    }
}
