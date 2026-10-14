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


namespace AnimeDb\Plugins\AnimedbWorldart\Tests\Fixture;

use Dom\HTMLDocument;
use Dom\Text;
use Dom\XPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keeps site-authored prose (reviews, descriptions) out of the public repository.
 */
final class FixtureContentTest extends TestCase
{
    private const MAX_TEXT_NODE_LENGTH = 200;

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        foreach (glob(__DIR__.'/*.html') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('fixtures')]
    public function testNoLongTextNodes(string $path): void
    {
        $document = HTMLDocument::createFromString((string) file_get_contents($path), LIBXML_NOERROR, 'windows-1251');

        $long = [];
        foreach (new XPath($document)->query('//text()') as $node) {
            if (!$node instanceof Text) {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/u', ' ', $node->data));
            if (mb_strlen($text) > self::MAX_TEXT_NODE_LENGTH) {
                $long[] = mb_substr($text, 0, 40).'…';
            }
        }

        self::assertSame([], $long, 'Replace site-authored text with a placeholder.');
    }
}
