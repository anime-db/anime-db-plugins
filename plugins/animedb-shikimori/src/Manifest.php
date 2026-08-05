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

namespace AnimeDb\Plugins\AnimedbShikimori;

/**
 * Reads plugin identity fields from the plugin's own `manifest.json` at runtime, so the plugin
 * id and version each have exactly one source of truth instead of being duplicated as literals
 * elsewhere in the code.
 */
final class Manifest
{
    private const PATH = __DIR__.'/../manifest.json';
    private const FALLBACK_ID = 'animedb-shikimori';
    private const FALLBACK_VERSION = '0.0.0';

    public static function getId(): string
    {
        $id = self::read()['id'] ?? null;

        return \is_string($id) && $id !== '' ? $id : self::FALLBACK_ID;
    }

    public static function getVersion(): string
    {
        $version = self::read()['version'] ?? null;

        return \is_string($version) && $version !== '' ? $version : self::FALLBACK_VERSION;
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(): array
    {
        $raw = @file_get_contents(self::PATH);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
