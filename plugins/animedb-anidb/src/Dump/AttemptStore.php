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

namespace AnimeDb\Plugins\AnimedbAnidb\Dump;

use AnimeDb\PluginContracts\Settings\ConcurrentWriteException;
use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;

/**
 * The durable record of the last dump download attempt — time and kind — kept as one entry in
 * the plugin's settings, so it survives clearing of the cache directory. The kind selects the
 * interval before the next attempt: a received HTTP response (any status) waits 24 hours, a
 * transport failure without a response waits 1 hour.
 *
 * The settings write can fail (repeated write conflicts, any other store error), and the limit
 * must not depend on it. Every attempt is therefore also kept in the process and in a marker
 * file in the cache directory; a new download is due only when none of the three records is
 * younger than its interval.
 */
final class AttemptStore
{
    public const KIND_RESPONSE = 'response';
    public const KIND_TRANSPORT_FAILURE = 'transport_failure';

    public const RESPONSE_INTERVAL = 86400;
    public const TRANSPORT_FAILURE_INTERVAL = 3600;

    private const KEY = 'dump_attempt';
    private const WRITE_ATTEMPTS = 5;

    /** @var array{at: int, kind: string}|null */
    private ?array $local = null;

    public function __construct(
        private readonly SettingsStoreInterface $settings,
        private readonly ?DumpFiles $files = null,
    ) {
    }

    public function isDue(int $now): bool
    {
        $records = [$this->local, $this->readMarker()];
        try {
            $records[] = $this->settings->read()[self::KEY] ?? null;
        } catch (\Throwable) {
        }

        foreach ($records as $record) {
            if (!self::isDueAgainst($record, $now)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Records the attempt in the process and the marker file first, then in the settings
     * (retrying while the settings lock is held by another writer). A failed settings write
     * is swallowed: the other two records still hold the limit.
     */
    public function record(string $kind, int $now): void
    {
        $this->local = ['at' => $now, 'kind' => $kind];
        $this->writeMarker($this->local);

        for ($attempt = 1; $attempt <= self::WRITE_ATTEMPTS; ++$attempt) {
            try {
                $this->settings->update(static fn (array $settings): array => [
                    ...$settings,
                    self::KEY => ['at' => $now, 'kind' => $kind],
                ]);

                return;
            } catch (ConcurrentWriteException) {
                usleep(20000 * $attempt);
            } catch (\Throwable) {
                return;
            }
        }
    }

    private static function isDueAgainst(mixed $record, int $now): bool
    {
        if (
            !is_array($record)
            || !isset($record['at'], $record['kind'])
            || !is_int($record['at'])
        ) {
            return true;
        }

        $interval = match ($record['kind']) {
            self::KIND_RESPONSE => self::RESPONSE_INTERVAL,
            self::KIND_TRANSPORT_FAILURE => self::TRANSPORT_FAILURE_INTERVAL,
            default => 0,
        };

        return $now >= $record['at'] + $interval;
    }

    private function readMarker(): mixed
    {
        if ($this->files === null || !is_file($this->files->attemptPath())) {
            return null;
        }
        $raw = @file_get_contents($this->files->attemptPath());

        return is_string($raw) ? json_decode($raw, true) : null;
    }

    /**
     * @param array{at: int, kind: string} $record
     */
    private function writeMarker(array $record): void
    {
        if ($this->files === null) {
            return;
        }
        try {
            $this->files->writeAtomic($this->files->attemptPath(), json_encode($record, \JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
        }
    }
}
