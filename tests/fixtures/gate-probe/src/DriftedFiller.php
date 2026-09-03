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

namespace AnimeDb\Plugins\Tools\Tests\Fixtures\GateProbe;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;

/**
 * Deliberate violator of ContractConformanceRule: resolveExternalId() accepts a wider
 * parameter type than ExternalIdResolutionInterface declares — legal under PHP's variance
 * rules, so nothing but the rule reports it. That is the drift the rule was written for
 * (see its docblock), and it is deliberately not the narrowing-a-return-type case: the one
 * time the gate reported that shape it turned out to be the contract's own imprecision, not
 * plugin drift, and whether the rule should report it at all is a live question. A fixture
 * must not quietly turn one answer into the specification.
 *
 * Test data for {@see \AnimeDb\Plugins\Tools\Tests\AnalysePluginCliTest}. It also pins where
 * the contract comes from: this fixture directory has no composer.json and no vendor/ of its
 * own, so AnimeDb\PluginContracts\* can only resolve through this repository's vendor/ — the
 * property tools/phpstan-plugin.neon.dist relies on when it refuses to trust a plugin's own
 * copy of the contract.
 *
 * This file is never executed. It is only ever read by PHPStan.
 */
final class DriftedFiller implements FillerInterface
{
    /**
     * Drifted: the contract declares `string[]`, i.e. `array<string>`.
     *
     * @param mixed[] $urls
     */
    public function resolveExternalId(array $urls): ?string
    {
        return null;
    }

    /**
     * @param callable(): void|null $onHeartbeat
     *
     * @return SearchByPluginCandidate[]
     */
    public function find(string $name, ?callable $onHeartbeat = null): array
    {
        return [];
    }

    public function findById(string $externalId): ?PluginAnimeData
    {
        return null;
    }

    /**
     * @return string[]
     */
    public function getFillableFields(): array
    {
        return [];
    }
}
