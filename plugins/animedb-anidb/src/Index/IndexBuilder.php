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

namespace AnimeDb\Plugins\AnimedbAnidb\Index;

use AnimeDb\Plugins\AnimedbAnidb\Dump\DumpFiles;

/**
 * Builds the SQLite title index from the dump file into a temporary file and moves it onto the
 * index path with `rename()`. Lines are `aid|type|lang|title`; `#` lines are comments. Only the
 * four known title types are indexed, so anything else (e.g. `kanareading`) never gets in.
 * Must be called under the lock.
 */
final class IndexBuilder
{
    private const TYPE_MAIN = 1;

    /** Rank per dump type: main, official, synonym, short. */
    private const RANKS = [1 => 0, 4 => 1, 2 => 2, 3 => 3];

    public function __construct(
        private readonly DumpFiles $files,
    ) {
    }

    public function build(string $version): void
    {
        $handle = fopen($this->files->dumpPath(), 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot read the dump file.');
        }

        $tmp = $this->files->temporaryPath();

        try {
            $pdo = new \PDO('sqlite:'.$tmp, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
            $pdo->exec('CREATE TABLE anime (aid INTEGER PRIMARY KEY, main TEXT NOT NULL)');
            $pdo->exec('CREATE TABLE names (norm TEXT NOT NULL, aid INTEGER NOT NULL, rank INTEGER NOT NULL)');

            $mains = [];
            $fallbacks = [];
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO names (norm, aid, rank) VALUES (?, ?, ?)');

            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '' || $line[0] === '#') {
                    continue;
                }

                $parts = explode('|', $line, 4);
                if (count($parts) !== 4 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
                    continue;
                }

                $type = (int) $parts[1];
                if (!isset(self::RANKS[$type])) {
                    continue;
                }

                $aid = (int) $parts[0];
                $title = $parts[3];
                $norm = TitleNormalizer::normalize($title);
                if ($norm === '') {
                    continue;
                }

                if ($type === self::TYPE_MAIN) {
                    $mains[$aid] ??= $title;
                } else {
                    $fallbacks[$aid] ??= $title;
                }
                $insert->execute([$norm, $aid, self::RANKS[$type]]);
            }

            $insertAnime = $pdo->prepare('INSERT INTO anime (aid, main) VALUES (?, ?)');
            foreach ($mains + $fallbacks as $aid => $main) {
                $insertAnime->execute([$aid, $main]);
            }

            $pdo->exec('CREATE INDEX names_norm ON names (norm, rank, aid)');
            $pdo->prepare('INSERT INTO meta (k, v) VALUES (?, ?)')->execute(['version', $version]);
            $pdo->commit();
            $pdo = null;
            fclose($handle);

            if (!rename($tmp, $this->files->indexPath())) {
                throw new \RuntimeException('Cannot move the index into place.');
            }
        } catch (\Throwable $e) {
            @unlink($tmp);

            throw $e;
        }
    }
}
