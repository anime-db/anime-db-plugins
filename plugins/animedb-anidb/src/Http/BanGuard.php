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

namespace AnimeDb\Plugins\AnimedbAnidb\Http;

use AnimeDb\PluginContracts\Settings\ConcurrentWriteException;
use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;

/**
 * Stops requests while an AniDB ban window is open. The "do not call until" moment is kept in
 * the plugin settings, not in the cache directory, so it survives cache cleaning and a plugin
 * reinstall; each request during a ban would extend it.
 */
final class BanGuard
{
    public const BAN_SECONDS = 86400;

    private const KEY = 'api_banned_until';
    private const WRITE_ATTEMPTS = 5;

    private int $localUntil = 0;

    /**
     * @param \Closure(): int|null $clock
     */
    public function __construct(
        private readonly SettingsStoreInterface $settings,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * @throws AniDbRequestException while the ban window is open
     */
    public function assertNotBanned(): void
    {
        $until = $this->localUntil;
        try {
            $stored = $this->settings->read()[self::KEY] ?? null;
            if (\is_int($stored)) {
                $until = max($until, $stored);
            }
        } catch (\Throwable) {
        }

        if ($until > $this->now()) {
            throw new AniDbRequestException('AniDB has banned this client, requests are suspended.');
        }
    }

    public function markBanned(): void
    {
        $until = $this->now() + self::BAN_SECONDS;
        $this->localUntil = $until;

        for ($attempt = 1; $attempt <= self::WRITE_ATTEMPTS; ++$attempt) {
            try {
                // the modifier does no I/O and never touches the store again (nested call = deadlock)
                $this->settings->update(static fn (array $settings): array => [...$settings, self::KEY => $until]);

                return;
            } catch (ConcurrentWriteException) {
                usleep(20000 * $attempt);
            } catch (\Throwable) {
                return;
            }
        }
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
