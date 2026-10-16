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


namespace AnimeDb\Plugins\AnimedbFolderInfo\Widget;

use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use AnimeDb\PluginContracts\Widget\WidgetMetadata;
use AnimeDb\Plugins\AnimedbFolderInfo\Folder\FolderService;
use AnimeDb\Plugins\AnimedbFolderInfo\Folder\FolderStatus;
use Twig\Environment;

/**
 * Entry widget showing the state of the plugin's files in the record folder and the button that
 * writes them. Opening the card only inspects the folder, nothing is written without a click.
 */
final class FolderWidget implements EntryWidgetInterface
{
    private const TEMPLATE = '@AnimedbFolderInfo/widget/folder.html.twig';

    public function __construct(
        private readonly FolderService $folder,
        private readonly Environment $twig,
    ) {
    }

    public static function metadata(): WidgetMetadata
    {
        return new WidgetMetadata(
            'folder',
            'widget.folder.title',
            'widget.folder.description',
        );
    }

    public function render(AnimeId $anime): string
    {
        return $this->renderStatus($anime, $this->folder->status($anime), null);
    }

    /**
     * @param string|null $error translation key of a message shown above the status
     */
    public function renderStatus(AnimeId $anime, FolderStatus $status, ?string $error): string
    {
        return $this->twig->render(self::TEMPLATE, [
            'animeId' => $anime->value,
            'status' => $status->value,
            'canWrite' => $status->canWrite(),
            'overwrite' => $status === FolderStatus::Modified,
            'writtenAt' => $status === FolderStatus::Written ? $this->folder->writtenAt($anime) : null,
            'error' => $error,
        ]);
    }
}
