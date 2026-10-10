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
 * Reads the ready SQLite index. Takes no lock: the index file is only ever replaced whole.
 *
 * Ranking: exact matches of the normalized title are the only result when there are any;
 * otherwise prefix matches that end on a word boundary (the query is a whole-word beginning of the title). Substring matches are never returned. Within a level, matches are
 * ordered by title type (main, official, synonym, short), then by `aid`.
 */
final class IndexReader
{
    public function __construct(
        private readonly DumpFiles $files,
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->files->indexPath());
    }

    public function version(): ?string
    {
        if (!$this->exists()) {
            return null;
        }

        try {
            $stmt = $this->open()->query("SELECT v FROM meta WHERE k = 'version'");
            $value = $stmt === false ? false : $stmt->fetchColumn();
        } catch (\PDOException) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<array{aid: int, name: string}> one entry per aid, name is the main title
     */
    public function search(string $query, int $limit): array
    {
        $norm = TitleNormalizer::normalize($query);
        if ($norm === '' || !$this->exists()) {
            return [];
        }

        $pdo = $this->open();

        $rows = $this->fetch($pdo, 'n.norm = :q', ['q' => $norm], $limit);
        if ($rows === []) {
            $rows = $this->fetch(
                $pdo,
                'n.norm >= :lo AND n.norm < :hi',
                ['lo' => $norm.' ', 'hi' => $norm.'!'],
                $limit,
            );
        }

        return $rows;
    }

    /**
     * @param array<string, string> $params
     *
     * @return list<array{aid: int, name: string}>
     */
    private function fetch(\PDO $pdo, string $where, array $params, int $limit): array
    {
        $stmt = $pdo->prepare(
            'SELECT a.aid AS aid, a.main AS main FROM names n JOIN anime a ON a.aid = n.aid '
            .'WHERE '.$where.' GROUP BY a.aid ORDER BY MIN(n.rank), a.aid LIMIT '.$limit,
        );
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[] = ['aid' => (int) $row['aid'], 'name' => (string) $row['main']];
        }

        return $result;
    }

    private function open(): \PDO
    {
        return new \PDO('sqlite:'.$this->files->indexPath(), null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
