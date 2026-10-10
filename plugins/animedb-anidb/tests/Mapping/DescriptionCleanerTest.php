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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\Mapping;

use AnimeDb\Plugins\AnimedbAnidb\Mapping\DescriptionCleaner;
use PHPUnit\Framework\TestCase;

final class DescriptionCleanerTest extends TestCase
{
    public function testLinkBecomesItsLabel(): void
    {
        self::assertSame(
            'Based on the game by Lune.',
            DescriptionCleaner::clean('Based on the game by http://anidb.net/cr123 [Lune].'),
        );
    }

    public function testSourceTailAndNoteLinesAreRemoved(): void
    {
        $raw = "First line.\nNote: Refer to http://wiki.anidb.net/w/Foo [the Wiki page] for details.\nSecond line.\nSource: Wikipedia";

        self::assertSame("First line.\nSecond line.", DescriptionCleaner::clean($raw));
    }

    public function testSourceTailWithoutColonAfterKeyword(): void
    {
        self::assertSame('Text.', DescriptionCleaner::clean("Text.\nSource ANN:"));
    }

    public function testPlainTextIsUnchanged(): void
    {
        $text = "A plain description.\nWith a second line; a source of trouble, a note of caution.";

        self::assertSame($text, DescriptionCleaner::clean($text));
    }

    public function testLiveFixtureDescription(): void
    {
        $clean = DescriptionCleaner::clean((string) FixtureCard::load(2500)->description);

        self::assertStringStartsWith('* Based on the erotic game by Lune.', $clean);
        self::assertStringContainsString('Momiji`s chastity is taken by Kazuto.', $clean);
        self::assertStringNotContainsString('anidb.net', $clean);
        self::assertStringNotContainsString('Source', $clean);
    }

    public function testLanguageIsEnglish(): void
    {
        self::assertSame('en', DescriptionCleaner::LANGUAGE);
    }
}
