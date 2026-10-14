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

namespace AnimeDb\Plugins\AnimedbWorldart\Tests\Support;

use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;

/**
 * Pages for the mapper tests: the live fixtures (raw `windows-1251` bytes) and small UTF-8
 * fragments built in the pattern of the pairs "label → value" of those fixtures.
 */
final class CardFactory
{
    public static function fixture(string $name): WorldartPage
    {
        return WorldartPage::fromBytes((string) file_get_contents(__DIR__.'/../Fixture/'.$name));
    }

    /**
     * @param array<string, string> $pairs label => HTML of the value cell
     */
    public static function fragment(array $pairs, string $title = 'Заголовок', string $extra = ''): WorldartPage
    {
        $html = '<html><head><meta charset="utf-8"></head><body><table><tr><td><font size=5>'.$title.'</font></td></tr></table>';
        foreach ($pairs as $label => $value) {
            $html .= "<table><tr><td align=left width=145 class='review' Valign=top><b>".$label.'</b></td><td width=15></td>'
                ."<td class='review' Valign=top>".$value.'</td></tr></table>';
        }

        return WorldartPage::fromBytes($html.$extra.'</body></html>');
    }

    /**
     * @return list<string> the cards with the pairs "label → value"
     */
    public static function cards(): array
    {
        return [
            'card_1.html', 'card_6.html', 'card_1500.html', 'card_10000.html', 'card_11466.html',
            'card_movie_20.html', 'card_ova_100.html', 'card_tv_special_8000.html', 'card_web_11000.html',
        ];
    }
}
