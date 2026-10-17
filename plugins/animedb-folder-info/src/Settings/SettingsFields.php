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

/**
 * Keys of the plugin's {@see \AnimeDb\PluginContracts\Settings\SettingsStoreInterface} and the
 * ids of the settings forms' CSRF tokens.
 */
final class SettingsFields
{
    /** Bool, true when the key is missing: the automation is on by default, also after an update from 0.1.0. */
    public const AUTO_FILL = 'auto_fill';

    /** String id of the current "fill all" run; a newer click replaces it and stops the older chain. */
    public const RUN_ID = 'run_id';

    /** Int|null, the last processed AnimeId::$value of the current run, null before the first batch. */
    public const RUN_CURSOR = 'run_cursor';

    /** Array with the counters and `finished_at` of the last finished run. */
    public const LAST_RUN = 'last_run';

    public const CSRF_TOKEN_ID = 'animedb_folder_info_settings';

    public const ACTION_SAVE = 'save';
    public const ACTION_FILL_ALL = 'fill_all';

    private function __construct()
    {
    }
}
