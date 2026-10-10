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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\Http;

use AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface;
use AnimeDb\Plugins\AnimedbAnidb\Http\AniDbRequestException;
use AnimeDb\Plugins\AnimedbAnidb\Http\BanGuard;
use AnimeDb\Plugins\AnimedbAnidb\Tests\Support\ArraySettingsStore;
use PHPUnit\Framework\TestCase;

final class BanGuardTest extends TestCase
{
    private int $now = 1_800_000_000;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/anidb-ban-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testNotBannedByDefault(): void
    {
        $this->guard(new ArraySettingsStore())->assertNotBanned();

        $this->expectNotToPerformAssertions();
    }

    public function testBanIsStoredInSettingsForTwentyFourHours(): void
    {
        $settings = new ArraySettingsStore();
        $settings->data = ['other' => 'kept'];
        $this->guard($settings)->markBanned();

        self::assertSame($this->now + 86400, $settings->data['api_banned_until']);
        self::assertSame('kept', $settings->data['other']);
    }

    public function testBanBlocksUntilItExpiresEvenForANewInstance(): void
    {
        $settings = new ArraySettingsStore();
        $this->guard($settings)->markBanned();

        $fresh = $this->guard($settings);
        $this->now += 86399;
        try {
            $fresh->assertNotBanned();
            self::fail('Expected AniDbRequestException.');
        } catch (AniDbRequestException) {
        }

        $this->now += 1;
        $fresh->assertNotBanned();
        $this->addToAssertionCount(1);
    }

    public function testWriteIsRetriedOnConcurrentWrite(): void
    {
        $settings = new ArraySettingsStore();
        $settings->failNextUpdates = 2;
        $this->guard($settings)->markBanned();

        self::assertSame(3, $settings->updateCalls);
        self::assertSame($this->now + 86400, $settings->data['api_banned_until']);
    }

    public function testBanHoldsInProcessWhenSettingsWriteFails(): void
    {
        $settings = new ArraySettingsStore();
        $settings->failNextUpdates = 100;
        $guard = $this->guard($settings);
        $guard->markBanned();

        $this->expectException(AniDbRequestException::class);
        $guard->assertNotBanned();
    }

    public function testBanHoldsInOtherProcessesWhenSettingsWriteAlwaysFails(): void
    {
        $broken = new ArraySettingsStore();
        $broken->failNextUpdates = 100;
        $this->guard($broken)->markBanned();

        $otherProcess = $this->guard(new ArraySettingsStore());
        $this->now += 86399;
        try {
            $otherProcess->assertNotBanned();
            self::fail('Expected AniDbRequestException.');
        } catch (AniDbRequestException) {
        }

        $this->now += 1;
        $otherProcess->assertNotBanned();
        $this->addToAssertionCount(1);
    }

    public function testBanHoldsFromSettingsWhenMarkerIsGone(): void
    {
        $settings = new ArraySettingsStore();
        $this->guard($settings)->markBanned();
        array_map('unlink', glob($this->dir.'/*') ?: []);

        $this->expectException(AniDbRequestException::class);
        $this->guard($settings)->assertNotBanned();
    }

    public function testUnavailableCacheDirectoryDoesNotBreakTheGuard(): void
    {
        $directory = $this->createMock(PluginCacheDirectoryInterface::class);
        $directory->method('path')->willThrowException(new \RuntimeException('no dir'));
        $guard = new BanGuard(new ArraySettingsStore(), $directory, fn (): int => $this->now);

        $guard->assertNotBanned();
        $guard->markBanned();

        $this->expectException(AniDbRequestException::class);
        $guard->assertNotBanned();
    }

    private function guard(ArraySettingsStore $settings): BanGuard
    {
        $directory = $this->createMock(PluginCacheDirectoryInterface::class);
        $directory->method('path')->willReturn($this->dir);

        return new BanGuard($settings, $directory, fn (): int => $this->now);
    }
}
