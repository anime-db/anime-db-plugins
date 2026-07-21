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

namespace AnimeDb\Plugins\Tools;

/**
 * Builds a deterministic distributable ZIP of a single plugin directory of this monorepo.
 *
 * The plugin contract at runtime is `manifest.json` + `src/*.php`; the host provides
 * `anime-db/plugin-contracts` itself, so `vendor/` must not be duplicated into the archive.
 * `composer.lock`, `tests/`, `.git*` and `.php-cs-fixer.*` are dev-only artifacts of this
 * monorepo's local tooling and are excluded the same way.
 *
 * Entries are added in a fixed (sorted) order with a fixed mtime and fixed unix file
 * permissions, so building the same plugin directory twice produces byte-identical ZIPs
 * (and therefore the same sha256) regardless of the filesystem's own entry order or mtimes.
 *
 * This determinism is guaranteed within the same toolchain (PHP/libzip/zlib version): the
 * deflate stream itself is not pinned, so an independently built zlib on another machine may
 * produce a different compressed byte stream (and thus a different sha256) for identical
 * input. The published sha256 is a per-build integrity check, not a reproducible-build
 * attestation across toolchains.
 */
final class PluginZipBuilder
{
    /**
     * Top-level directories entirely excluded from the archive (recursively).
     *
     * @var list<string>
     */
    private const EXCLUDED_TOP_LEVEL_DIRS = ['vendor', 'tests'];

    /**
     * File basenames excluded from the archive wherever they appear.
     *
     * @var list<string>
     */
    private const EXCLUDED_FILES = ['composer.lock'];

    /**
     * Basename prefixes excluded from the archive wherever they appear (covers `.git`,
     * `.gitignore`, `.gitattributes`, `.github`, `.php-cs-fixer.dist.php`, `.php-cs-fixer.cache`).
     *
     * @var list<string>
     */
    private const EXCLUDED_PREFIXES = ['.git', '.php-cs-fixer'];

    /**
     * Fixed mtime stamped on every archive entry: 1980-01-01T00:00:00Z, the earliest
     * timestamp the ZIP format itself supports. Using the real filesystem mtime would make
     * the archive (and its sha256) depend on when/where it was checked out.
     */
    private const FIXED_TIMESTAMP = 315532800;

    /**
     * Fixed unix permissions (regular file, rw-r--r--) stamped on every archive entry, so the
     * archive does not depend on the umask of whoever built it.
     */
    private const FIXED_UNIX_MODE = 0o100644;

    /**
     * Builds the archive and returns its sha256 hex digest.
     */
    public function build(string $pluginDir, string $outZip): string
    {
        $pluginDir = rtrim($pluginDir, '/');

        if (!is_dir($pluginDir)) {
            throw new \RuntimeException(\sprintf('Plugin directory "%s" does not exist.', $pluginDir));
        }

        if (is_file($outZip)) {
            unlink($outZip);
        }

        // libzip converts the Unix timestamp passed to setMtimeName() into the entry's DOS
        // date/time field via localtime(), so the resulting bytes (and thus the sha256) would
        // otherwise depend on the builder machine's TZ. Pin UTC for the duration of the build.
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $zip = new \ZipArchive();
            if ($zip->open($outZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException(\sprintf('Failed to create zip archive "%s".', $outZip));
            }

            foreach (self::collectFiles($pluginDir) as $relativePath) {
                if (!$zip->addFile($pluginDir.'/'.$relativePath, $relativePath)) {
                    throw new \RuntimeException(\sprintf('Failed to add file "%s" to zip archive.', $relativePath));
                }
                $zip->setMtimeName($relativePath, self::FIXED_TIMESTAMP);
                $zip->setExternalAttributesName(
                    $relativePath,
                    \ZipArchive::OPSYS_UNIX,
                    self::FIXED_UNIX_MODE << 16,
                );
            }

            $zip->close();
        } finally {
            date_default_timezone_set($previousTz);
        }

        $sha256 = hash_file('sha256', $outZip);
        \assert($sha256 !== false);

        return $sha256;
    }

    /**
     * @return list<string> plugin-relative file paths, sorted for a stable archive order
     */
    private static function collectFiles(string $pluginDir): array
    {
        // Symlinks are skipped, not followed: plugin source may come from an untrusted
        // community PR, and a symlink (e.g. pointing at "/" or "..") must not be able to pull
        // files from outside $pluginDir into the archive, or cause infinite recursion on a cycle.
        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($pluginDir, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $fileInfo, string $key, \RecursiveDirectoryIterator $iterator): bool => !$fileInfo->isLink() && !self::isExcluded($iterator->getSubPathname()),
        );
        $iterator = new \RecursiveIteratorIterator($filter);

        $files = [];
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                $files[] = $iterator->getSubPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private static function isExcluded(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);
        $basename = end($segments);

        if (\in_array($segments[0], self::EXCLUDED_TOP_LEVEL_DIRS, true)) {
            return true;
        }

        if (\in_array($basename, self::EXCLUDED_FILES, true)) {
            return true;
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($basename, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
