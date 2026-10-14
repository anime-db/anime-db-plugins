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

namespace AnimeDb\Plugins\AnimedbWorldart\Tests\Mapping;

use AnimeDb\Plugins\AnimedbWorldart\Mapping\SynopsisMapper;
use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;
use PHPUnit\Framework\TestCase;

final class SynopsisMapperTest extends TestCase
{
    public function testFixtureGivesOnlyTheTextOfTheVariant(): void
    {
        $page = WorldartPage::fromBytes((string) file_get_contents(__DIR__.'/../Fixture/synopsis_12242.html'));

        self::assertTrue($page->isSynopsisPage());
        $result = SynopsisMapper::map($page);
        self::assertSame(['ru' => 'Текст краткого содержания заменён заглушкой.'], $result);
        foreach (['текст взят с другого сайта', 'автор:', 'NightStar', '2026.08.29'] as $leak) {
            self::assertStringNotContainsString($leak, $result['ru']);
        }
    }

    public function testVariantWithMoreLikesWins(): void
    {
        $page = $this->page($this->block('Первый', 1).$this->block('Второй', 5).$this->block('Третий', 3));

        self::assertSame(['ru' => 'Второй'], SynopsisMapper::map($page));
    }

    public function testTieGoesToTheFirstVariant(): void
    {
        $page = $this->page($this->block('Первый', 2).$this->block('Второй', 2));

        self::assertSame(['ru' => 'Первый'], SynopsisMapper::map($page));
    }

    public function testParagraphsKeepABlankLine(): void
    {
        $page = $this->page($this->block('Строка 1<br>Строка 2<br><br>Абзац 2<br><br><i>текст взят с другого сайта</i>', 0));

        self::assertSame(['ru' => "Строка 1\nСтрока 2\n\nАбзац 2"], SynopsisMapper::map($page));
    }

    public function testItalicsInsideTheTextAreKept(): void
    {
        $page = $this->page($this->block('Герои ищут <i>Меч Короля</i>, чтобы победить.<br><br><i>текст взят с другого сайта</i>', 0));

        self::assertSame(['ru' => 'Герои ищут Меч Короля, чтобы победить.'], SynopsisMapper::map($page));
    }

    public function testLoginBlockIsNotAVariant(): void
    {
        $page = $this->page("<div class='comment_block'><a href='http://www.world-art.ru/enter.php'>Войдите в систему</a>, чтобы написать.</div>");

        self::assertNull(SynopsisMapper::map($page));
    }

    private function page(string $blocks): WorldartPage
    {
        return WorldartPage::fromBytes('<html><head><meta charset="utf-8"></head><body><h3>X &#151; страница редактирования кратких описаний</h3>'.$blocks.'</body></html>');
    }

    private function block(string $text, int $likes): string
    {
        return "<div class='comment_block'><table><tr><td class='review'>".$text.'</td></tr><tr><td class=\'review\'><hr></td></tr>'
            ."<tr><td class='review'><table><tr><td>автор: <a href='#'>A</a> | 2026.01.01</td>"
            ."<td><img src='http://www.world-art.ru/img/comment_like.png'></td><td class='review'> <b>".$likes.'</b></td></tr></table></td></tr></table></div>';
    }
}
