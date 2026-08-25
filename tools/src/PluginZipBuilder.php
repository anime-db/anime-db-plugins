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

namespace AnimeDb\Plugins\Tools;

/**
 * Builds a deterministic distributable ZIP of a single plugin directory of this monorepo.
 *
 * The plugin contract at runtime is `manifest.json` + `src/*.php`; the host provides
 * `anime-db/plugin-contracts` itself, so `vendor/` must not be duplicated into the archive.
 * `composer.lock`, `tests/`, `.git*` and `.php-cs-fixer.*` are dev-only artifacts of this
 * monorepo's local tooling and are excluded the same way — see {@see PublishedContentRules}
 * for the exact rules, shared with the CI gate that requires a version bump whenever this
 * same published content changes.
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
     * Archive-root name for the injected monorepo LICENSE (GPL-3.0 full text). A plugin does
     * not ship its own copy, so the same repository-root LICENSE is added to every archive.
     */
    private const LICENSE_ENTRY_NAME = 'LICENSE';

    /**
     * Builds the archive and returns its sha256 hex digest.
     *
     * When $licenseFile is given it is added to the archive root as `LICENSE`, so the
     * distributed plugin ships the full GPL-3.0 text its file headers reference (GPLv3 §4).
     */
    public function build(string $pluginDir, string $outZip, ?string $licenseFile = null): string
    {
        $pluginDir = rtrim($pluginDir, '/');

        if (!is_dir($pluginDir)) {
            throw new \RuntimeException(\sprintf('Plugin directory "%s" does not exist.', $pluginDir));
        }

        if ($licenseFile !== null && !is_file($licenseFile)) {
            throw new \RuntimeException(\sprintf('License file "%s" does not exist.', $licenseFile));
        }

        if (is_file($outZip)) {
            unlink($outZip);
        }

        // archive-entry name => absolute source path. The injected LICENSE is merged into the
        // same map and the whole thing is re-sorted, so the archive entry order — and thus the
        // sha256 — stays deterministic regardless of where "LICENSE" falls alphabetically.
        $entries = [];
        foreach (self::collectFiles($pluginDir) as $relativePath) {
            $entries[$relativePath] = $pluginDir.'/'.$relativePath;
        }
        if ($licenseFile !== null) {
            $entries[self::LICENSE_ENTRY_NAME] = $licenseFile;
        }
        ksort($entries, SORT_STRING);

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

            foreach ($entries as $archiveName => $sourcePath) {
                if (!$zip->addFile($sourcePath, $archiveName)) {
                    throw new \RuntimeException(\sprintf('Failed to add file "%s" to zip archive.', $archiveName));
                }
                $zip->setMtimeName($archiveName, self::FIXED_TIMESTAMP);
                $zip->setExternalAttributesName(
                    $archiveName,
                    \ZipArchive::OPSYS_UNIX,
                    self::FIXED_UNIX_MODE << 16,
                );
            }

            if ($zip->close() !== true) {
                throw new \RuntimeException(\sprintf('Failed to finalize zip archive "%s".', $outZip));
            }
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
            static fn (\SplFileInfo $fileInfo, string $key, \RecursiveDirectoryIterator $iterator): bool => !$fileInfo->isLink() && !PublishedContentRules::isExcluded($iterator->getSubPathname()),
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
}
