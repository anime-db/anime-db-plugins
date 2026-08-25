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

use AnimeDb\Plugins\Tools\TagExistenceChecker;
use AnimeDb\Plugins\Tools\TagExistenceCheckFailedException;

final class FakeTagExistenceChecker implements TagExistenceChecker
{
    /**
     * @param list<string> $existingTags  tags shaped "<id>/<version>" that already exist
     * @param list<string> $failingChecks tags shaped "<id>/<version>" for which the check
     *                                    itself fails (simulates a network/tooling error,
     *                                    as opposed to a definitive "not found")
     */
    public function __construct(
        private readonly array $existingTags = [],
        private readonly array $failingChecks = [],
    ) {
    }

    public function exists(string $pluginId, string $version): bool
    {
        $tag = $pluginId.'/'.$version;

        if (\in_array($tag, $this->failingChecks, true)) {
            throw new TagExistenceCheckFailedException(\sprintf('simulated check failure for "%s"', $tag));
        }

        return \in_array($tag, $this->existingTags, true);
    }
}
