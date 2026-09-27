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

namespace AnimeDb\Plugins\AnimedbMediaDetails\Tests\Fixture;

use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\PluginData\PluginDataStoreInterface;

/**
 * In-memory {@see PluginDataStoreInterface}, so a test can assert on {@see self::$data}
 * directly instead of through mock call-argument matchers.
 */
final class FakePluginDataStore implements PluginDataStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $data = [];

    public int $writeCallCount = 0;

    private bool $throwOnWrite = false;

    /**
     * @param array<string, mixed> $initial
     */
    public function seed(AnimeId $anime, array $initial): void
    {
        $this->data[$anime->value] = $initial;
    }

    public function read(AnimeId $anime): array
    {
        return $this->data[$anime->value] ?? [];
    }

    public function write(AnimeId $anime, array $data): void
    {
        ++$this->writeCallCount;

        if ($this->throwOnWrite) {
            throw new \RuntimeException('Simulated write conflict.');
        }

        $this->data[$anime->value] = $data;
    }

    public function failOnNextWrite(): void
    {
        $this->throwOnWrite = true;
    }
}
