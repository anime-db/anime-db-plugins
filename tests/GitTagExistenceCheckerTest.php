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

use AnimeDb\Plugins\Tools\GitTagExistenceChecker;
use AnimeDb\Plugins\Tools\TagExistenceCheckFailedException;
use PHPUnit\Framework\TestCase;

/**
 * Exercises {@see GitTagExistenceChecker} against a real local "origin" (a bare repo reached over
 * a file path, needing no network) instead of a fake, since its whole job is to interpret real
 * `git ls-remote --exit-code` outcomes. Every test runs with the process CWD moved away from the
 * repo it passes to the constructor, to guard against a regression back to relying on the CWD
 * instead of the explicitly passed repo root.
 */
final class GitTagExistenceCheckerTest extends TestCase
{
    private string $localRepo;
    private string $remoteRepo;
    private string|false $originalCwd = false;

    protected function setUp(): void
    {
        $this->remoteRepo = sys_get_temp_dir().'/git-tag-checker-remote-'.bin2hex(random_bytes(8));
        $this->localRepo = sys_get_temp_dir().'/git-tag-checker-local-'.bin2hex(random_bytes(8));

        self::runGit(['init', '--bare', '-q', $this->remoteRepo], sys_get_temp_dir());
        self::runGit(['init', '-q', $this->localRepo], sys_get_temp_dir());
        self::runGit(['config', 'user.email', 'test@example.com'], $this->localRepo);
        self::runGit(['config', 'user.name', 'Test'], $this->localRepo);
        self::runGit(['commit', '--allow-empty', '-q', '-m', 'init'], $this->localRepo);
        self::runGit(['tag', 'animedb-shikimori/1.0.0'], $this->localRepo);
        self::runGit(['remote', 'add', 'origin', $this->remoteRepo], $this->localRepo);
        self::runGit(['push', '-q', 'origin', 'refs/tags/animedb-shikimori/1.0.0'], $this->localRepo);

        $this->originalCwd = getcwd();
        chdir(sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== false) {
            chdir($this->originalCwd);
        }
        self::removeDirectory($this->localRepo);
        self::removeDirectory($this->remoteRepo);
    }

    public function testExistingTagIsFoundRegardlessOfProcessCwd(): void
    {
        self::assertNotSame($this->localRepo, getcwd());

        $checker = new GitTagExistenceChecker($this->localRepo);

        self::assertTrue($checker->exists('animedb-shikimori', '1.0.0'));
    }

    public function testMissingTagReturnsFalseRegardlessOfProcessCwd(): void
    {
        self::assertNotSame($this->localRepo, getcwd());

        $checker = new GitTagExistenceChecker($this->localRepo);

        self::assertFalse($checker->exists('animedb-shikimori', '9.9.9'));
    }

    public function testUnreachableRemoteThrowsTagExistenceCheckFailedException(): void
    {
        self::runGit(['remote', 'set-url', 'origin', $this->remoteRepo.'-does-not-exist'], $this->localRepo);

        $checker = new GitTagExistenceChecker($this->localRepo);

        $this->expectException(TagExistenceCheckFailedException::class);

        $checker->exists('animedb-shikimori', '1.0.0');
    }

    /**
     * @param list<string> $args
     */
    private static function runGit(array $args, string $cwd): void
    {
        $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, trim(($output ?: '').($errorOutput ?: '')));
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \FilesystemIterator($dir) as $item) {
            if ($item->isDir()) {
                self::removeDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
