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

namespace AnimeDb\Plugins\AnimedbWorldart\Mapping;

use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;

/**
 * The description from the page of unmoderated short descriptions: among the variants
 * (`div.comment_block` holding a `td.review` with text) the one with the most likes, the first one
 * on a tie. Only the text of the first `td.review` is taken; the italic "text taken from another
 * site" mark, the author line and the like counter are not part of it. No variant → `null`.
 */
final class SynopsisMapper
{
    /**
     * @return array<string, string>|null
     */
    public static function map(WorldartPage $page): ?array
    {
        $best = null;
        $bestLikes = -1;
        foreach ($page->commentBlocks() as $block) {
            $text = self::variantText($page, $block);
            if ($text === null) {
                continue;
            }
            $likes = self::likes($page, $block);
            if ($likes > $bestLikes) {
                $best = $text;
                $bestLikes = $likes;
            }
        }

        return $best === null ? null : ['ru' => $best];
    }

    private static function variantText(WorldartPage $page, \Dom\Element $block): ?string
    {
        $cell = $page->xpath()->query('.//x:td[contains(concat(" ", normalize-space(@class), " "), " review ")]', $block)->item(0);
        if (!$cell instanceof \Dom\Element) {
            return null;
        }

        $copy = $cell->cloneNode(true);
        foreach ($page->xpath()->query('.//x:i', $copy) as $mark) {
            if ($mark instanceof \Dom\Element) {
                $mark->remove();
            }
        }
        $text = WorldartPage::textWithParagraphs($copy);

        return $text === '' ? null : $text;
    }

    private static function likes(WorldartPage $page, \Dom\Element $block): int
    {
        $counter = $page->xpath()->query(
            './/x:td[x:img[contains(@src, "comment_like.png")]]/following-sibling::x:td[1]//x:b',
            $block,
        )->item(0);

        return $counter !== null && preg_match('/^\d+$/', WorldartPage::clean((string) $counter->textContent)) === 1
            ? (int) WorldartPage::clean((string) $counter->textContent)
            : 0;
    }
}
