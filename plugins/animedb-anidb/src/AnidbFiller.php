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

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use AnimeDb\PluginContracts\Model\AnimeName;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\Plugins\AnimedbAnidb\ExternalId\AnidbIdResolver;
use AnimeDb\Plugins\AnimedbAnidb\Http\AniDbApiClient;
use AnimeDb\Plugins\AnimedbAnidb\Http\NotFoundHttpException;
use AnimeDb\Plugins\AnimedbAnidb\Mapping\AnimeTypeMapper;
use AnimeDb\Plugins\AnimedbAnidb\Mapping\CardFieldsMapper;
use AnimeDb\Plugins\AnimedbAnidb\Mapping\DateParser;
use AnimeDb\Plugins\AnimedbAnidb\Mapping\DescriptionCleaner;
use AnimeDb\Plugins\AnimedbAnidb\Mapping\TagMapper;
use AnimeDb\Plugins\AnimedbAnidb\Mapping\TitleMapper;

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
final class AnidbFiller implements FillerInterface
{
    private const SEARCH_LIMIT = 10;

    public function __construct(
        private readonly TitleSearch $titleSearch,
        private readonly AniDbApiClient $client,
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

    /**
     * Fills a card from the AniDB API. The card comes only through {@see AniDbApiClient}
     * (24-hour card cache, request limiter, ban guard): AniDB bans clients that repeat
     * requests for the same data. A non-existent `aid` gives `null`; every other client
     * failure (including an open ban window and an overfull limiter queue) is propagated.
     *
     * Cards with `restricted="true"` are filled like any other: the API returns them in full
     * without a login.
     *
     * `images` and `countries` are not filled: the response has no such data.
     */
    public function findById(string $externalId): ?PluginAnimeData
    {
        if (preg_match('/^[1-9]\d{0,8}$/', $externalId) !== 1) {
            return null;
        }

        try {
            $card = $this->client->fetchAnime((int) $externalId);
        } catch (NotFoundHttpException) {
            return null;
        }

        $title = TitleMapper::mainTitle($card);
        if ($title === null) {
            return null;
        }

        $tags = TagMapper::map($card);

        $datePremiere = DateParser::parseStart(self::text($card, '/anime/startdate'));
        $dateEnd = DateParser::parseEnd(self::text($card, '/anime/enddate'));
        // The host rejects `dateEnd < datePremiere`; a partial `enddate` rounded to the end of
        // its period can still land before a fully-dated `startdate` of the same card.
        if ($datePremiere !== null && $dateEnd !== null && $dateEnd < $datePremiere) {
            $dateEnd = null;
        }

        $description = DescriptionCleaner::clean(self::text($card, '/anime/description') ?? '');
        $studios = CardFieldsMapper::studios($card);

        return new PluginAnimeData(
            title: $title,
            alternativeNames: self::buildAlternativeNames($title, $card),
            descriptions: $description === '' ? null : [DescriptionCleaner::LANGUAGE => $description],
            genres: $tags['genres'] === [] ? null : $tags['genres'],
            themes: $tags['themes'] === [] ? null : $tags['themes'],
            demographic: $tags['demographics'][0] ?? null,
            studios: $studios === [] ? null : $studios,
            type: AnimeTypeMapper::map(self::text($card, '/anime/type')),
            datePremiere: $datePremiere,
            dateEnd: $dateEnd,
            durationMinutes: CardFieldsMapper::duration($card),
            episodesCount: CardFieldsMapper::episodeCount($card),
            cover: CardFieldsMapper::coverUrl($card),
        );
    }

    /**
     * @return list<string>
     */
    public function getFillableFields(): array
    {
        return [
            'title',
            'alternativeNames',
            'descriptions',
            'genres',
            'themes',
            'demographic',
            'type',
            'datePremiere',
            'dateEnd',
            'durationMinutes',
            'episodesCount',
            'studios',
            'cover',
        ];
    }

    /**
     * Empty names and names equal to `$title` are skipped; pairs (name, locale) are unique.
     *
     * @return list<AnimeName>|null
     */
    private static function buildAlternativeNames(string $title, \SimpleXMLElement $card): ?array
    {
        $seen = [];
        $names = [];
        foreach (TitleMapper::names($card) as $name) {
            $value = $name->name;
            $key = ($name->locale ?? '')."\0".$value;
            if ($value === '' || $value === $title || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = $name;
        }

        return $names === [] ? null : $names;
    }

    private static function text(\SimpleXMLElement $card, string $path): ?string
    {
        $nodes = $card->xpath($path);
        if ($nodes === false || $nodes === null || $nodes === []) {
            return null;
        }

        $value = trim((string) $nodes[0]);

        return $value === '' ? null : $value;
    }
}
