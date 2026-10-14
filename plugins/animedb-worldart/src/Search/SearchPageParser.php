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

namespace AnimeDb\Plugins\AnimedbWorldart\Search;

use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;

/**
 * Reads the rows of a search page in the order of the site: `<td><a href="animation/animation.php?id=N">
 * Name&nbsp;(year, ...)</a><br>other name<br>...<br><br></td>`. One result per id.
 */
final class SearchPageParser
{
    private function __construct()
    {
    }

    /**
     * @return list<SearchResult>
     */
    public static function parse(WorldartPage $page): array
    {
        $results = [];
        $links = $page->xpath()->query('//x:td/x:a[@href][contains(@href, "animation.php?id=")]');
        foreach ($links as $link) {
            if (!$link instanceof \Dom\Element) {
                continue;
            }
            if (preg_match('#(?:^|/)animation\.php\?id=([1-9]\d*)$#', (string) $link->getAttribute('href'), $matches) !== 1) {
                continue;
            }
            $id = $matches[1];
            if (isset($results[$id])) {
                continue;
            }

            $text = WorldartPage::clean((string) $link->textContent);
            $year = null;
            if (preg_match('/\s*\((\d{4})\b[^()]*\)$/u', $text, $tail, \PREG_OFFSET_CAPTURE) === 1) {
                $year = (int) $tail[1][0];
                $text = substr($text, 0, $tail[0][1]);
            }
            if ($text === '') {
                continue;
            }

            $otherNames = [];
            for ($node = $link->nextSibling; $node !== null; $node = $node->nextSibling) {
                if ($node instanceof \Dom\Text) {
                    $line = WorldartPage::clean($node->data);
                    if ($line !== '') {
                        $otherNames[] = $line;
                    }
                }
            }

            $results[$id] = new SearchResult($id, $text, $otherNames, $year);
        }

        return array_values($results);
    }
}
