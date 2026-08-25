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
 * The single definition of which files inside a plugin directory count as "published
 * content" — i.e. end up in the distributable ZIP {@see PluginZipBuilder} builds.
 *
 * Two consumers read this list: {@see PluginZipBuilder} (building the archive) and
 * {@see VersionBumpChecker} (the CI gate that requires a `manifest.json` version bump
 * whenever published content changes). They must agree on exactly the same files, or the
 * gate either demands a bump for content that never ships, or silently waves through
 * content that does — so the list lives here once, not copied into either consumer.
 *
 * This class only classifies *paths*. It has no notion of symlinks — a path-based check
 * cannot see that a given path is a symlink without touching the filesystem. It also does
 * not know about the monorepo-root `LICENSE` file {@see PluginZipBuilder} injects into
 * every archive at build time: that file lives outside every plugin directory, so no
 * plugin-relative or repo-relative path could ever name it. Both gaps are intentional
 * scope boundaries of a pure path-list check, not oversights — see `.claude-docs/gotchas.md`.
 */
final class PublishedContentRules
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
     * @param string $pluginRelativePath a path relative to the plugin directory's own root
     *                                   (e.g. "src/Widget.php", "tests/WidgetTest.php") — NOT relative to the repository
     *                                   root. A caller holding a repo-relative path (e.g. "plugins/<id>/tests/x.php")
     *                                   must go through {@see self::isExcludedRepoRelative()} instead.
     */
    public static function isExcluded(string $pluginRelativePath): bool
    {
        $segments = explode('/', $pluginRelativePath);
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

    /**
     * Explicit repo-relative → plugin-relative conversion for a caller (the CI gate) that
     * only has paths shaped `plugins/<id>/<rest>` (e.g. from `git diff --name-only`), not
     * the plugin-relative paths {@see PluginZipBuilder} works with internally.
     *
     * @return bool|null null if $repoRelativePath is not inside `plugins/<pluginId>/` at all
     */
    public static function isExcludedRepoRelative(string $pluginId, string $repoRelativePath): ?bool
    {
        $prefix = 'plugins/'.$pluginId.'/';

        if (!str_starts_with($repoRelativePath, $prefix)) {
            return null;
        }

        return self::isExcluded(substr($repoRelativePath, \strlen($prefix)));
    }
}
