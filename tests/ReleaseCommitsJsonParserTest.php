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

use AnimeDb\Plugins\Tools\ReleaseCommitsJsonParser;
use PHPUnit\Framework\TestCase;

final class ReleaseCommitsJsonParserTest extends TestCase
{
    public function testParsesCommitWithPullRequests(): void
    {
        $json = <<<'JSON'
            [
              {
                "sha": "abcdef1",
                "subject": "feat(shikimori): oauth",
                "author": "peter-gribanov",
                "prs": [
                  {"number": 27, "title": "Shikimori: OAuth", "url": "https://github.com/anime-db/anime-db-plugins/pull/27", "author": "openronin"}
                ]
              }
            ]
            JSON;

        $commits = (new ReleaseCommitsJsonParser())->parse($json);

        self::assertCount(1, $commits);
        self::assertSame('abcdef1', $commits[0]->sha);
        self::assertCount(1, $commits[0]->pullRequests);
        self::assertSame(27, $commits[0]->pullRequests[0]->number);
    }

    public function testParsesCommitWithoutPullRequests(): void
    {
        $json = '[{"sha": "abcdef1", "subject": "chore: x", "author": "a", "prs": []}]';

        $commits = (new ReleaseCommitsJsonParser())->parse($json);

        self::assertCount(1, $commits);
        self::assertSame([], $commits[0]->pullRequests);
    }

    public function testMissingPrsFieldDefaultsToEmpty(): void
    {
        $json = '[{"sha": "abcdef1", "subject": "chore: x", "author": "a"}]';

        $commits = (new ReleaseCommitsJsonParser())->parse($json);

        self::assertSame([], $commits[0]->pullRequests);
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ReleaseCommitsJsonParser())->parse('not json');
    }

    public function testNonArrayJsonThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ReleaseCommitsJsonParser())->parse('{"sha": "x"}');
    }

    public function testCommitMissingRequiredFieldThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ReleaseCommitsJsonParser())->parse('[{"sha": "abcdef1"}]');
    }
}
