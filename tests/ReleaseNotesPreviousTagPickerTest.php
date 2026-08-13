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

use AnimeDb\Plugins\Tools\ReleaseNotesPreviousTagPicker;
use PHPUnit\Framework\TestCase;

final class ReleaseNotesPreviousTagPickerTest extends TestCase
{
    public function testPicksTheGreatestVersionStrictlyBelowTarget(): void
    {
        $previous = (new ReleaseNotesPreviousTagPicker())->pickPrevious('0.3.0', [
            'animedb-shikimori/0.1.0',
            'animedb-shikimori/0.2.0',
            'animedb-shikimori/0.3.0',
        ]);

        self::assertSame('animedb-shikimori/0.2.0', $previous);
    }

    public function testFirstReleaseHasNoPreviousVersion(): void
    {
        $previous = (new ReleaseNotesPreviousTagPicker())->pickPrevious('0.1.0', []);

        self::assertNull($previous);
    }

    public function testNoTagBelowTargetVersionHasNoPreviousVersion(): void
    {
        $previous = (new ReleaseNotesPreviousTagPicker())->pickPrevious('0.1.0', [
            'animedb-shikimori/0.1.0',
            'animedb-shikimori/0.2.0',
        ]);

        self::assertNull($previous);
    }

    public function testComparisonIsSemanticNotLexicographic(): void
    {
        $previous = (new ReleaseNotesPreviousTagPicker())->pickPrevious('10.0.0', [
            'animedb-shikimori/2.0.0',
            'animedb-shikimori/9.0.0',
        ]);

        self::assertSame('animedb-shikimori/9.0.0', $previous);
    }

    public function testTagsOutOfOrderAreStillHandledCorrectly(): void
    {
        $previous = (new ReleaseNotesPreviousTagPicker())->pickPrevious('0.4.0', [
            'animedb-shikimori/0.3.0',
            'animedb-shikimori/0.1.0',
            'animedb-shikimori/0.2.0',
        ]);

        self::assertSame('animedb-shikimori/0.3.0', $previous);
    }

    public function testGarbageLinesAreIgnored(): void
    {
        $previous = (new ReleaseNotesPreviousTagPicker())->pickPrevious('0.3.0', [
            '',
            'not-a-tag',
            'animedb-shikimori/not-a-version',
            'animedb-shikimori/0.2.0',
            'some-other-repo-tag',
        ]);

        self::assertSame('animedb-shikimori/0.2.0', $previous);
    }

    public function testInvalidTargetVersionThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ReleaseNotesPreviousTagPicker())->pickPrevious('not-a-version', ['animedb-shikimori/0.1.0']);
    }
}
