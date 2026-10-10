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

namespace AnimeDb\Plugins\AnimedbAnidb\Widget;

/**
 * Maps the `type` attribute of an AniDB `<relatedanime>` entry to a translation key of the
 * plugin catalog. An unknown or missing type gives the "other" label.
 */
final class RelationType
{
    private const OTHER = 'widget.relation.other';

    private const KEYS = [
        'Sequel' => 'widget.relation.sequel',
        'Prequel' => 'widget.relation.prequel',
        'Same Setting' => 'widget.relation.same_setting',
        'Alternative Setting' => 'widget.relation.alternative_setting',
        'Alternative Version' => 'widget.relation.alternative_version',
        'Music Video' => 'widget.relation.music_video',
        'Character' => 'widget.relation.character',
        'Side Story' => 'widget.relation.side_story',
        'Parent Story' => 'widget.relation.parent_story',
        'Summary' => 'widget.relation.summary',
        'Full Story' => 'widget.relation.full_story',
        'Other' => self::OTHER,
    ];

    private function __construct()
    {
    }

    public static function translationKey(string $type): string
    {
        return self::KEYS[trim($type)] ?? self::OTHER;
    }

    /**
     * @return list<string>
     */
    public static function translationKeys(): array
    {
        return array_values(array_unique(self::KEYS));
    }
}
