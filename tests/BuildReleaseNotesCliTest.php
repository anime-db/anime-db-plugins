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

use PHPUnit\Framework\TestCase;

/**
 * Smoke-tests tools/build-release-notes.php as an actual CLI process, on top of the unit
 * coverage of {@see \AnimeDb\Plugins\Tools\ReleaseNotesPreviousTagPicker} and
 * {@see \AnimeDb\Plugins\Tools\ReleaseNotesFormatter}.
 */
final class BuildReleaseNotesCliTest extends TestCase
{
    private ?string $stdinFile = null;

    protected function tearDown(): void
    {
        if ($this->stdinFile !== null && is_file($this->stdinFile)) {
            unlink($this->stdinFile);
        }
        $this->stdinFile = null;
    }

    public function testPickPrevPrintsPreviousTagAndExitsZero(): void
    {
        [$output, $exitCode] = $this->runCli(['pick-prev', '0.2.0'], "animedb-shikimori/0.1.0\nanimedb-shikimori/0.2.0\n");

        self::assertSame(0, $exitCode);
        self::assertSame(['animedb-shikimori/0.1.0'], $output);
    }

    public function testPickPrevFirstReleasePrintsEmptyLine(): void
    {
        [$output, $exitCode] = $this->runCli(['pick-prev', '0.1.0'], '');

        self::assertSame(0, $exitCode);
        self::assertSame([''], $output);
    }

    public function testPickPrevMissingVersionExitsNonZero(): void
    {
        [, $exitCode] = $this->runCli(['pick-prev'], '');

        self::assertNotSame(0, $exitCode);
    }

    public function testFormatPrintsMarkdownAndExitsZero(): void
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

        [$output, $exitCode] = $this->runCli(
            ['format', '--id', 'animedb-shikimori', '--version', '0.2.0', '--repo', 'anime-db/anime-db-plugins', '--prev', 'animedb-shikimori/0.1.0'],
            $json,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
        $notes = implode("\n", $output);
        self::assertStringContainsString('- Shikimori: OAuth by @openronin in [#27](https://github.com/anime-db/anime-db-plugins/pull/27)', $notes);
        self::assertStringContainsString('**Полный список изменений:**', $notes);
    }

    public function testFormatMissingRequiredFlagExitsNonZero(): void
    {
        [, $exitCode] = $this->runCli(['format', '--id', 'animedb-shikimori'], '[]');

        self::assertNotSame(0, $exitCode);
    }

    public function testFormatMalformedJsonExitsNonZeroAndPrintsNothingToStdout(): void
    {
        [$output, $exitCode] = $this->runCli(
            ['format', '--id', 'animedb-shikimori', '--version', '0.2.0', '--repo', 'anime-db/anime-db-plugins'],
            'not json',
        );

        self::assertNotSame(0, $exitCode);
        self::assertSame([], $output);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: list<string>, 1: int}
     */
    private function runCli(array $args, string $stdin): array
    {
        $repoRoot = \dirname(__DIR__);
        $this->stdinFile = sys_get_temp_dir().'/build-release-notes-cli-test-'.bin2hex(random_bytes(8));
        file_put_contents($this->stdinFile, $stdin);

        $cmd = escapeshellarg(\PHP_BINARY).' '.escapeshellarg($repoRoot.'/tools/build-release-notes.php');
        foreach ($args as $arg) {
            $cmd .= ' '.escapeshellarg($arg);
        }
        $cmd .= ' < '.escapeshellarg($this->stdinFile);

        exec($cmd, $output, $exitCode);

        return [$output, $exitCode];
    }
}
