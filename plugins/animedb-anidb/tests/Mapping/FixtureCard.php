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

namespace AnimeDb\Plugins\AnimedbAnidb\Tests\Mapping;

final class FixtureCard
{
    public static function load(int $aid): \SimpleXMLElement
    {
        $card = simplexml_load_file(\sprintf('%s/../Fixture/card_%d.xml', __DIR__, $aid));
        if (!$card instanceof \SimpleXMLElement) {
            throw new \RuntimeException(\sprintf('Fixture card_%d.xml is not readable.', $aid));
        }

        return $card;
    }

    public static function fromString(string $xml): \SimpleXMLElement
    {
        $card = simplexml_load_string($xml);
        if (!$card instanceof \SimpleXMLElement) {
            throw new \RuntimeException('Invalid test XML.');
        }

        return $card;
    }
}
