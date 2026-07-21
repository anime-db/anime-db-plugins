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
 * Gates a PR to touching exactly one plugin, and only files inside its `plugins/<id>/`
 * directory — the market registry's CI needs this before it can pick which single plugin
 * to run {@see PluginValidator} against.
 *
 * A pure function of a path list, deliberately independent of git, so it is fully testable
 * without a repository: the caller (a CLI script, in this monorepo's case) is responsible
 * for turning a `git diff` into the path list this class consumes.
 */
final class PrChangeChecker
{
    /**
     * @param string[] $paths changed paths, relative to the repository root
     */
    public function check(array $paths): PrChangeCheckResult
    {
        $paths = array_values(array_filter(array_map('trim', $paths), static fn (string $path): bool => $path !== ''));

        if ($paths === []) {
            return new PrChangeCheckResult(null, ['No changed paths given.']);
        }

        $errors = [];
        $pluginIds = [];

        foreach ($paths as $path) {
            $normalized = ltrim($path, '/');

            if ($normalized === 'plugins-directory.json') {
                $errors[] = 'plugins-directory.json is a CI-generated artifact and must not be edited manually.';
                continue;
            }

            $segments = explode('/', $normalized);
            if (($segments[0] ?? null) !== 'plugins' || !isset($segments[1]) || $segments[1] === '') {
                $errors[] = \sprintf('Path "%s" is outside plugins/<id>/.', $path);
                continue;
            }

            $pluginIds[$segments[1]] = true;
        }

        $ids = array_keys($pluginIds);
        sort($ids);

        if (\count($ids) > 1) {
            $errors[] = \sprintf('Changes touch multiple plugins: %s.', implode(', ', $ids));
        }

        if ($ids === [] && $errors === []) {
            $errors[] = 'No plugin path found among the changed paths.';
        }

        if ($errors !== []) {
            return new PrChangeCheckResult(null, $errors);
        }

        return new PrChangeCheckResult($ids[0], []);
    }
}
