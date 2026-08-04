<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace AnimeDb\Plugins\Tools\Tests;

use AnimeDb\Plugins\Tools\MirrorAssetUrl;
use PHPUnit\Framework\TestCase;

final class MirrorAssetUrlTest extends TestCase
{
    public function testValidHttpsTemplateWithAllMacrosIsValid(): void
    {
        self::assertTrue(MirrorAssetUrl::isValidTemplate('https://mirror.tld/<id>/<version>/<file>'));
    }

    public function testHttpTemplateIsInvalid(): void
    {
        self::assertFalse(MirrorAssetUrl::isValidTemplate('http://mirror.tld/<id>/<version>/<file>'));
    }

    /**
     * @dataProvider provideMissingMacro
     */
    public function testTemplateMissingAMacroIsInvalid(string $template): void
    {
        self::assertFalse(MirrorAssetUrl::isValidTemplate($template));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMissingMacro(): iterable
    {
        yield 'missing <id>' => ['https://mirror.tld/<version>/<file>'];
        yield 'missing <version>' => ['https://mirror.tld/<id>/<file>'];
        yield 'missing <file>' => ['https://mirror.tld/<id>/<version>'];
        yield 'not a url at all' => ['not-a-url'];
    }

    public function testBuildSubstitutesAllMacros(): void
    {
        self::assertSame(
            'https://mirror.tld/animedb-shikimori/0.1.0/plugin.zip',
            MirrorAssetUrl::build('https://mirror.tld/<id>/<version>/<file>', 'animedb-shikimori', '0.1.0', 'plugin.zip'),
        );
    }
}
