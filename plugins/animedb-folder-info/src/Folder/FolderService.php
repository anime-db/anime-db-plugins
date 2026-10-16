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


namespace AnimeDb\Plugins\AnimedbFolderInfo\Folder;

use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;
use AnimeDb\PluginContracts\Folder\ForeignFileException;
use AnimeDb\PluginContracts\Folder\RecordFolderFileState;
use AnimeDb\PluginContracts\Folder\RecordFolderWriterInterface;
use AnimeDb\PluginContracts\Folder\RecordHasNoFolderException;
use AnimeDb\PluginContracts\Folder\StorageNotWritableException;
use AnimeDb\PluginContracts\Media\StorageUnavailableException;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\PluginData\PluginDataStoreInterface;
use Twig\Environment;

/**
 * Writes the record description and cover into the record's own folder and reports their state.
 *
 * Every byte goes through the host's {@see RecordFolderWriterInterface}: the plugin passes bare
 * file names and contents, never a path. The cover is written first because the description
 * links to it by the file name the host chose (the extension is the host's decision).
 *
 * The host does not report the cover file name nor the write time, so both are kept in the
 * plugin's own data store: `cover_file` (string|null) and `written_at` (unix timestamp).
 */
final class FolderService
{
    public const INFO_FILE = 'animedb-info.html';
    public const COVER_BASE_NAME = 'animedb-cover';

    private const TEMPLATE = '@AnimedbFolderInfo/info.html.twig';

    public function __construct(
        private readonly RecordFolderWriterInterface $writer,
        private readonly CatalogReaderInterface $catalog,
        private readonly PluginDataStoreInterface $store,
        private readonly Environment $twig,
    ) {
    }

    /**
     * The worst state of the plugin's files: Modified over Absent over Written.
     */
    public function status(AnimeId $anime): FolderStatus
    {
        try {
            $states = [$this->writer->inspect($anime, self::INFO_FILE)];

            $cover = $this->store->read($anime)['cover_file'] ?? null;
            if (\is_string($cover) && $cover !== '') {
                $states[] = $this->writer->inspect($anime, $cover);
            }
        } catch (RecordHasNoFolderException) {
            return FolderStatus::NoFolder;
        } catch (StorageUnavailableException) {
            return FolderStatus::Unavailable;
        } catch (StorageNotWritableException) {
            return FolderStatus::NotWritable;
        }

        if (\in_array(RecordFolderFileState::Modified, $states, true)) {
            return FolderStatus::Modified;
        }

        return \in_array(RecordFolderFileState::Absent, $states, true) ? FolderStatus::Absent : FolderStatus::Written;
    }

    /**
     * Unix time of the last complete write, null if there was none.
     */
    public function writtenAt(AnimeId $anime): ?int
    {
        $writtenAt = $this->store->read($anime)['written_at'] ?? null;

        return \is_int($writtenAt) ? $writtenAt : null;
    }

    /**
     * Writes all the plugin's files. Folder problems are returned as a status, nothing else is
     * written after the first one.
     *
     * @throws \DomainException the record does not exist
     */
    public function write(AnimeId $anime, bool $overwrite): FolderStatus
    {
        $view = $this->catalog->read($anime);
        if ($view === null) {
            throw new \DomainException('Record not found.');
        }

        try {
            $cover = $this->writer->writeCover($anime, self::COVER_BASE_NAME, $overwrite);
            $this->store->write($anime, ['cover_file' => $cover] + $this->store->read($anime));

            $html = $this->twig->render(self::TEMPLATE, ['anime' => $view, 'coverFile' => $cover]);
            $this->writer->write($anime, self::INFO_FILE, $html, $overwrite);
        } catch (ForeignFileException) {
            return FolderStatus::Modified;
        } catch (RecordHasNoFolderException) {
            return FolderStatus::NoFolder;
        } catch (StorageUnavailableException) {
            return FolderStatus::Unavailable;
        } catch (StorageNotWritableException) {
            return FolderStatus::NotWritable;
        }

        $this->store->write($anime, ['written_at' => time()] + $this->store->read($anime));

        return FolderStatus::Written;
    }
}
