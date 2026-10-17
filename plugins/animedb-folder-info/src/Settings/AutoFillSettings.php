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

namespace AnimeDb\Plugins\AnimedbFolderInfo\Settings;

use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;

/**
 * Typed access to the plugin's settings: the automation switch and the state of the
 * "fill all" run.
 */
final class AutoFillSettings
{
    public function __construct(private readonly SettingsStoreInterface $settings)
    {
    }

    /**
     * On unless explicitly switched off.
     */
    public function isAutoFillEnabled(): bool
    {
        return ($this->settings->read()[SettingsFields::AUTO_FILL] ?? true) !== false;
    }

    public function setAutoFill(bool $enabled): void
    {
        $this->settings->update(static fn (array $settings): array => [...$settings, SettingsFields::AUTO_FILL => $enabled]);
    }

    /**
     * Starts a new run: a new id, the cursor reset, the previous summary dropped.
     *
     * @return string the id of the new run
     */
    public function startRun(): string
    {
        $run = bin2hex(random_bytes(8));
        $this->settings->update(static function (array $settings) use ($run): array {
            unset($settings[SettingsFields::LAST_RUN]);

            return [...$settings, SettingsFields::RUN_ID => $run, SettingsFields::RUN_CURSOR => null];
        });

        return $run;
    }

    /**
     * Whether the batch described by `$run` and `$after` is the one the current run expects next.
     */
    public function isCurrent(mixed $run, mixed $after): bool
    {
        $settings = $this->settings->read();
        $current = $settings[SettingsFields::RUN_ID] ?? null;

        return \is_string($run) && $run !== '' && $run === $current
            && ($after === null || \is_int($after))
            && $after === ($settings[SettingsFields::RUN_CURSOR] ?? null);
    }

    /**
     * Moves the cursor of `$run`; does nothing, and returns false, if another run has started.
     */
    public function advance(string $run, int $cursor): bool
    {
        $moved = false;
        $this->settings->update(static function (array $settings) use ($run, $cursor, &$moved): array {
            if (($settings[SettingsFields::RUN_ID] ?? null) !== $run) {
                return $settings;
            }
            $moved = true;

            return [...$settings, SettingsFields::RUN_CURSOR => $cursor];
        });

        return $moved;
    }

    /**
     * @param array<string, int> $counters
     */
    public function finish(string $run, array $counters, int $finishedAt): void
    {
        $this->settings->update(static function (array $settings) use ($run, $counters, $finishedAt): array {
            if (($settings[SettingsFields::RUN_ID] ?? null) !== $run) {
                return $settings;
            }

            return [...$settings, SettingsFields::LAST_RUN => $counters + ['finished_at' => $finishedAt]];
        });
    }

    /**
     * @return array<string, int>|null the counters and `finished_at` of the last finished run
     */
    public function lastRun(): ?array
    {
        $last = $this->settings->read()[SettingsFields::LAST_RUN] ?? null;
        if (!\is_array($last)) {
            return null;
        }

        $result = [];
        foreach (['written', 'modified', 'no_folder', 'unavailable', 'not_writable', 'finished_at'] as $key) {
            $value = $last[$key] ?? 0;
            $result[$key] = \is_int($value) ? $value : 0;
        }

        return $result;
    }

    public function isRunning(): bool
    {
        $settings = $this->settings->read();

        return \is_string($settings[SettingsFields::RUN_ID] ?? null) && !\is_array($settings[SettingsFields::LAST_RUN] ?? null);
    }
}
