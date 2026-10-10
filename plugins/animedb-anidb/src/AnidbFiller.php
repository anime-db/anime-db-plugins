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

namespace AnimeDb\Plugins\AnimedbAnidb;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use AnimeDb\Plugins\AnimedbAnidb\ExternalId\AnidbIdResolver;

/**
 * Источник AniDB: поиск аниме по названию в локальном суточном дампе названий и
 * распознавание id по уже прикреплённым к записи ссылкам.
 *
 * Единственный класс плагина, на который опирается хост: расширение до `FillerInterface`
 * делается в этом же классе, а не в отдельном — второй filler-совместимый класс на тот же id
 * плагина дал бы коллизию тегов на хосте.
 *
 * Поиск отдаёт только точные совпадения нормализованного названия, а при их отсутствии —
 * префиксные; подстрочных совпадений нет: заполнение карточки берёт первого кандидата без
 * выбора и запоминает внешний id навсегда.
 */
final class AnidbFiller implements SearchByPluginInterface
{
    private const SEARCH_LIMIT = 10;

    public function __construct(
        private readonly TitleSearch $titleSearch,
        private readonly OwnManifestInterface $ownManifest,
    ) {
    }

    /**
     * Распознаёт id аниме на AniDB по уже прикреплённым к записи ссылкам.
     *
     * @param string[] $urls
     */
    public function resolveExternalId(array $urls): ?string
    {
        return AnidbIdResolver::resolve($urls);
    }

    /**
     * @param callable(): void|null $onHeartbeat
     *
     * @return list<SearchByPluginCandidate>
     */
    public function find(string $name, ?callable $onHeartbeat = null): array
    {
        $candidates = [];
        foreach ($this->titleSearch->search($name, self::SEARCH_LIMIT) as $match) {
            $candidates[] = new SearchByPluginCandidate(
                $this->ownManifest->id(),
                $match['name'],
                (string) $match['aid'],
            );
        }

        return $candidates;
    }
}
