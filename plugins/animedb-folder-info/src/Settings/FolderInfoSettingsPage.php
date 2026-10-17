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

use AnimeDb\PluginContracts\Settings\SettingsPageInterface;
use Twig\Environment;

/**
 * The plugin's settings page: the automation switch, the "fill all folders" button and the
 * summary of the last run.
 */
final class FolderInfoSettingsPage implements SettingsPageInterface
{
    private const TEMPLATE = '@AnimedbFolderInfo/settings.html.twig';

    public function __construct(
        private readonly AutoFillSettings $settings,
        private readonly Environment $twig,
    ) {
    }

    public function render(): string
    {
        return $this->renderWith(null, null);
    }

    /**
     * @param string|null $message translation key of a success message
     * @param string|null $error   translation key of an error message
     */
    public function renderWith(?string $message, ?string $error): string
    {
        $last = $this->settings->lastRun();

        return $this->twig->render(self::TEMPLATE, [
            'auto' => $this->settings->isAutoFillEnabled(),
            'running' => $this->settings->isRunning(),
            'last' => $last,
            'message' => $message,
            'error' => $error,
        ]);
    }
}
