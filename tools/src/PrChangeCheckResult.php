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
 * Result of {@see PrChangeChecker::check()}: either the id of the single plugin a PR's
 * changed paths touch, or the list of reasons that gate rejected the change set.
 */
final class PrChangeCheckResult
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public readonly ?string $pluginId,
        public readonly array $errors,
    ) {
    }

    public function isValid(): bool
    {
        return null !== $this->pluginId && [] === $this->errors;
    }
}
