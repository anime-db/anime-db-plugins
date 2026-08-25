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

namespace AnimeDb\Plugins\Tools\Tests;

use AnimeDb\Plugins\Tools\PublishedContentRules;
use PHPUnit\Framework\TestCase;

final class PublishedContentRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function pluginRelativeCases(): iterable
    {
        yield 'manifest.json is published' => ['manifest.json', false];
        yield 'src/ is published' => ['src/Widget.php', false];
        yield 'README.md is published' => ['README.md', false];
        yield 'vendor/ is excluded' => ['vendor/some-dep/Dep.php', true];
        yield 'tests/ is excluded' => ['tests/WidgetTest.php', true];
        yield 'composer.lock is excluded' => ['composer.lock', true];
        yield '.gitignore is excluded' => ['.gitignore', true];
        yield '.php-cs-fixer.dist.php is excluded' => ['.php-cs-fixer.dist.php', true];
    }

    /**
     * @dataProvider pluginRelativeCases
     */
    public function testIsExcluded(string $pluginRelativePath, bool $expected): void
    {
        self::assertSame($expected, PublishedContentRules::isExcluded($pluginRelativePath));
    }

    public function testIsExcludedRepoRelativeStripsThePluginPrefixBeforeClassifying(): void
    {
        self::assertFalse(PublishedContentRules::isExcludedRepoRelative('vendor-name', 'plugins/vendor-name/src/Widget.php'));
        self::assertTrue(PublishedContentRules::isExcludedRepoRelative('vendor-name', 'plugins/vendor-name/tests/WidgetTest.php'));
    }

    public function testIsExcludedRepoRelativeReturnsNullOutsideThePluginDirectory(): void
    {
        self::assertNull(PublishedContentRules::isExcludedRepoRelative('vendor-name', 'README.md'));
        self::assertNull(PublishedContentRules::isExcludedRepoRelative('vendor-name', 'plugins/other-plugin/src/Widget.php'));
    }
}
