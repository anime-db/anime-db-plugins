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

use AnimeDb\Plugins\Tools\BaseManifestReadFailedException;
use AnimeDb\Plugins\Tools\ManifestVersionSource;

final class FakeManifestVersionSource implements ManifestVersionSource
{
    /**
     * @param array<string, string> $base           plugin id => base-branch version (absent = plugin does not exist on base)
     * @param array<string, string> $head           plugin id => PR-head version (absent = plugin does not exist on head)
     * @param list<string>          $failingBaseIds plugin ids for which reading the base version itself fails
     *                                              (simulates e.g. an unresolvable base ref, as opposed to a
     *                                              definitive "plugin does not exist on the base branch")
     */
    public function __construct(
        private readonly array $base = [],
        private readonly array $head = [],
        private readonly array $failingBaseIds = [],
    ) {
    }

    public function baseVersion(string $pluginId): ?string
    {
        if (\in_array($pluginId, $this->failingBaseIds, true)) {
            throw new BaseManifestReadFailedException(\sprintf('simulated base read failure for "%s"', $pluginId));
        }

        return $this->base[$pluginId] ?? null;
    }

    public function headVersion(string $pluginId): ?string
    {
        return $this->head[$pluginId] ?? null;
    }
}
