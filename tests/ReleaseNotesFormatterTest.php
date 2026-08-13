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

use AnimeDb\Plugins\Tools\ReleaseNotesCommit;
use AnimeDb\Plugins\Tools\ReleaseNotesFormatter;
use AnimeDb\Plugins\Tools\ReleaseNotesPullRequest;
use PHPUnit\Framework\TestCase;

final class ReleaseNotesFormatterTest extends TestCase
{
    private const REPO = 'anime-db/anime-db-plugins';

    public function testCommitWithPullRequestUsesPrTitleAndAuthor(): void
    {
        $commits = [
            new ReleaseNotesCommit('abcdef1', 'feat(shikimori): oauth', 'peter-gribanov', [
                new ReleaseNotesPullRequest(27, 'Shikimori: OAuth', 'https://github.com/anime-db/anime-db-plugins/pull/27', 'openronin'),
            ]),
        ];

        $notes = (new ReleaseNotesFormatter())->format('animedb-shikimori', '0.2.0', self::REPO, 'animedb-shikimori/0.1.0', $commits);

        self::assertStringContainsString('- Shikimori: OAuth by @openronin in [#27](https://github.com/anime-db/anime-db-plugins/pull/27)', $notes);
        self::assertStringNotContainsString('oauth', $notes);
    }

    public function testCommitWithoutPullRequestUsesSubjectAsIs(): void
    {
        $commits = [
            new ReleaseNotesCommit('abcdef1', 'chore: bump manifest version', 'peter-gribanov', []),
        ];

        $notes = (new ReleaseNotesFormatter())->format('animedb-shikimori', '0.2.0', self::REPO, 'animedb-shikimori/0.1.0', $commits);

        self::assertStringContainsString('- chore: bump manifest version', $notes);
    }

    public function testMultipleCommitsOfSamePullRequestAreDeduplicated(): void
    {
        $pr = new ReleaseNotesPullRequest(27, 'Shikimori: OAuth', 'https://github.com/anime-db/anime-db-plugins/pull/27', 'openronin');
        $commits = [
            new ReleaseNotesCommit('cccccc1', 'fixup: typo', 'openronin', [$pr]),
            new ReleaseNotesCommit('bbbbbb1', 'feat: oauth impl', 'openronin', [$pr]),
        ];

        $notes = (new ReleaseNotesFormatter())->format('animedb-shikimori', '0.2.0', self::REPO, 'animedb-shikimori/0.1.0', $commits);

        self::assertSame(1, substr_count($notes, '#27'));
    }

    public function testMultiplePullRequestsEachGetTheirOwnLine(): void
    {
        $commits = [
            new ReleaseNotesCommit('sha1', 'feat: a', 'a', [
                new ReleaseNotesPullRequest(27, 'PR A', 'https://github.com/anime-db/anime-db-plugins/pull/27', 'openronin'),
            ]),
            new ReleaseNotesCommit('sha2', 'feat: b', 'b', [
                new ReleaseNotesPullRequest(28, 'PR B', 'https://github.com/anime-db/anime-db-plugins/pull/28', 'openronin'),
            ]),
        ];

        $notes = (new ReleaseNotesFormatter())->format('animedb-shikimori', '0.2.0', self::REPO, 'animedb-shikimori/0.1.0', $commits);

        self::assertStringContainsString('#27', $notes);
        self::assertStringContainsString('#28', $notes);
    }

    public function testFirstReleaseHasHeaderAndNoCompareLink(): void
    {
        $commits = [
            new ReleaseNotesCommit('sha1', 'feat: initial skeleton', 'peter-gribanov', []),
        ];

        $notes = (new ReleaseNotesFormatter())->format('animedb-shikimori', '0.1.0', self::REPO, null, $commits);

        self::assertStringContainsString('Первый релиз.', $notes);
        self::assertStringNotContainsString('compare', $notes);
    }

    public function testGivenPrevAddsCompareLink(): void
    {
        $commits = [
            new ReleaseNotesCommit('sha1', 'feat: something', 'peter-gribanov', []),
        ];

        $notes = (new ReleaseNotesFormatter())->format('animedb-shikimori', '0.2.0', self::REPO, 'animedb-shikimori/0.1.0', $commits);

        self::assertStringContainsString(
            '**Полный список изменений:** https://github.com/anime-db/anime-db-plugins/compare/animedb-shikimori/0.1.0...animedb-shikimori/0.2.0',
            $notes,
        );
        self::assertStringNotContainsString('Первый релиз.', $notes);
    }

    public function testSubjectAndPrTitleMarkdownSpecialCharsAreTreatedAsData(): void
    {
        $commits = [
            new ReleaseNotesCommit('sha1', '`rm -rf /` [click me](javascript:alert(1))', 'attacker', []),
            new ReleaseNotesCommit('sha2', 'feat: x', 'author', [
                new ReleaseNotesPullRequest(1, '*bold* & <script>alert(1)</script>', 'https://github.com/anime-db/anime-db-plugins/pull/1', 'attacker'),
            ]),
        ];

        $notes = (new ReleaseNotesFormatter())->format('animedb-shikimori', '0.2.0', self::REPO, 'animedb-shikimori/0.1.0', $commits);

        self::assertStringContainsString('- `rm -rf /` [click me](javascript:alert(1))', $notes);
        self::assertStringContainsString('*bold* & <script>alert(1)</script>', $notes);
    }
}
