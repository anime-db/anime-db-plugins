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

namespace AnimeDb\Plugins\AnimedbWorldart\Mapping;

use AnimeDb\Plugins\AnimedbWorldart\Page\WorldartPage;

/**
 * "Выпуск" (`c … по …`) gives both dates, "Премьера" only the premiere. A "Выпуск" without the
 * "по …" part has no end date. The guard "the end is earlier than the premiere → null" is not
 * here: it needs both dates at once and belongs to the assembly of the card.
 */
final class DatesMapper
{
    /**
     * @return array{premiere: ?\DateTimeImmutable, end: ?\DateTimeImmutable}
     */
    public static function map(WorldartPage $page): array
    {
        $release = $page->valueNode('Выпуск');
        if ($release !== null) {
            $text = WorldartPage::clean((string) $release->textContent);
            if (preg_match('/^[cс]\s+(\S+)(?:\s+по\s+(\S+))?/u', $text, $matches) === 1) {
                return [
                    'premiere' => DateParser::parseStart($matches[1]),
                    'end' => isset($matches[2]) ? DateParser::parseEnd($matches[2]) : null,
                ];
            }
        }

        $premiere = $page->valueNode('Премьера');

        return [
            'premiere' => $premiere === null ? null : DateParser::parseStart((string) $premiere->textContent),
            'end' => null,
        ];
    }
}
