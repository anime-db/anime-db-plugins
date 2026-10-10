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
use AnimeDb\Plugins\AnimedbAnidb\Http\CardCache;
use PHPUnit\Framework\TestCase;

final class CardCacheTest extends TestCase
{
    private string $dir;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/anidb-card-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testEmptyDirectoryIsAMiss(): void
    {
        self::assertNull($this->cache()->get(1));
    }

    public function testStoredXmlIsReturnedWhileYoungerThanADay(): void
    {
        $cache = $this->cache();
        $cache->put(7, '<anime id="7"/>');
        touch($cache->path(7), $this->now - 86399);

        self::assertSame('<anime id="7"/>', $cache->get(7));
        self::assertNull($cache->get(8));
    }

    public function testOlderThanADayIsAMiss(): void
    {
        $cache = $this->cache();
        $cache->put(7, '<anime id="7"/>');
        touch($cache->path(7), $this->now - 86400);

        self::assertNull($cache->get(7));
    }

    public function testNoTemporaryFilesAreLeftBehind(): void
    {
        $cache = $this->cache();
        $cache->put(7, '<anime id="7"/>');

        self::assertSame([$cache->path(7)], glob($this->dir.'/*'));
    }

    public function testPutIntoUnavailableDirectoryIsSilent(): void
    {
        $directory = $this->createMock(PluginCacheDirectoryInterface::class);
        $directory->method('path')->willReturn($this->dir.'/missing');

        (new CardCache($directory))->put(7000, '<anime/>');

        self::assertNull((new CardCache($directory))->get(7000));
    }

    public function testThrowingCacheDirectoryIsAMissAndNoOp(): void
    {
        $directory = $this->createMock(PluginCacheDirectoryInterface::class);
        $directory->method('path')->willThrowException(new \RuntimeException('no dir'));
        $cache = new CardCache($directory);

        $cache->put(7, '<anime/>');
        self::assertNull($cache->get(7));
    }

    private function cache(): CardCache
    {
        $directory = $this->createMock(PluginCacheDirectoryInterface::class);
        $directory->method('path')->willReturn($this->dir);

        return new CardCache($directory, fn (): int => $this->now);
    }
}
