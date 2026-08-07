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

namespace AnimeDb\Plugins\AnimedbShikimori\Tests\Settings\Fixture;

use AnimeDb\PluginContracts\Settings\ConcurrentWriteException;
use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;

/**
 * In-memory {@see SettingsStoreInterface}, so the controller's merge behaviour (does the
 * modifier closure really preserve unrelated keys, is it really called with the current
 * payload) can be asserted directly on {@see self::$data} instead of through mock
 * call-argument matchers.
 */
final class FakeSettingsStore implements SettingsStoreInterface
{
    /** @var array<string, mixed> */
    public array $data;

    private bool $throwOnUpdate = false;
    private bool $throwConcurrentWriteOnUpdate = false;

    /**
     * @param array<string, mixed> $initial
     */
    public function __construct(array $initial = [])
    {
        $this->data = $initial;
    }

    public function read(): array
    {
        return $this->data;
    }

    public function update(callable $modifier): void
    {
        if ($this->throwConcurrentWriteOnUpdate) {
            throw new ConcurrentWriteException('Simulated concurrent write.');
        }

        if ($this->throwOnUpdate) {
            throw new \RuntimeException('Simulated write failure.');
        }

        $this->data = $modifier($this->data);
    }

    public function failOnNextUpdate(): void
    {
        $this->throwOnUpdate = true;
    }

    public function throwConcurrentWriteOnNextUpdate(): void
    {
        $this->throwConcurrentWriteOnUpdate = true;
    }
}
