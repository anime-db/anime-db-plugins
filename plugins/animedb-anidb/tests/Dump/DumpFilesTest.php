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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\Dump;

use AnimeDb\Plugins\AnimedbAnidb\Dump\FileLock;
use AnimeDb\Plugins\AnimedbAnidb\Tests\Support\AnidbTestCase;

final class DumpFilesTest extends AnidbTestCase
{
    public function testVersionPrefersEtagThenLastModified(): void
    {
        self::assertNull($this->files->dumpVersion());

        file_put_contents($this->files->dumpPath(), 'x');
        $this->files->writeMeta('"e"', 'lm');
        self::assertSame('etag:"e"', $this->files->dumpVersion());

        $this->files->writeMeta(null, 'lm');
        self::assertSame('lm:lm', $this->files->dumpVersion());

        $this->files->writeMeta(null, null);
        self::assertStringStartsWith('file:1-', (string) $this->files->dumpVersion());
    }

    public function testCorruptMetaReadsAsEmpty(): void
    {
        file_put_contents($this->files->metaPath(), '{broken');

        self::assertSame(['etag' => null, 'last_modified' => null], $this->files->readMeta());
    }

    public function testLockFileIsSeparateAndKeptItsInode(): void
    {
        $lock = new FileLock();
        $lock->withLock($this->files->lockPath(), static fn (): null => null);
        $inode = fileinode($this->files->lockPath());

        $result = $lock->withLock($this->files->lockPath(), static fn (): string => 'done');

        self::assertSame('done', $result);
        self::assertSame($inode, fileinode($this->files->lockPath()));
        self::assertNotSame($this->files->lockPath(), $this->files->dumpPath());
        self::assertNotSame($this->files->lockPath(), $this->files->indexPath());
    }
}
