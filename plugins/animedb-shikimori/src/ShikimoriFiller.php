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

namespace AnimeDb\Plugins\AnimedbShikimori;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;

/**
 * Источник Shikimori — поиск и заполнение карточек аниме.
 *
 * Скелет плагина: контрактную границу с хостом уже держит целиком
 * ({@see FillerInterface} наследует SearchByPluginInterface, поэтому один класс
 * закрывает и поиск, и заполнение). Реально реализован пока только
 * {@see self::resolveExternalId()} — распознавание внешнего id по ссылкам на
 * Shikimori. Сам поиск и заполнение ({@see self::find()}/{@see self::findById()})
 * — заглушки: их наполнение через GraphQL API Shikimori (OAuth2, настраиваемый
 * endpoint) — следующий релиз плагина.
 *
 * DI-конфига и класса бандла у плагина нет намеренно: хост сам регистрирует
 * классы из `src/` как сервисы и тегирует их по контрактным интерфейсам.
 */
final class ShikimoriFiller implements FillerInterface
{
    /**
     * Распознаёт id аниме на Shikimori по уже прикреплённым к записи ссылкам.
     *
     * Матчит домены Shikimori (`shikimori.io`/`.one`/`.org`, в т.ч. поддомены) и
     * путь вида `/animes/<id>` или `/animes/z<id>-<slug>`, возвращает числовой id.
     *
     * @param string[] $urls
     */
    public function resolveExternalId(array $urls): ?string
    {
        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }

            $host = parse_url($url, \PHP_URL_HOST);
            if (!is_string($host) || preg_match('/(^|\.)shikimori\.(io|one|org)$/i', $host) !== 1) {
                continue;
            }

            $path = parse_url($url, \PHP_URL_PATH);
            if (is_string($path) && preg_match('#/animes/z?(\d+)#', $path, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @param callable(): void|null $onHeartbeat
     *
     * @return list<SearchByPluginCandidate>
     */
    public function find(string $name, ?callable $onHeartbeat = null): array
    {
        // TODO: поиск через GraphQL API Shikimori (OAuth2, настраиваемый endpoint).
        // Скелет — поиск ещё не реализован, наполнится следующим релизом плагина.
        return [];
    }

    public function findById(string $externalId): ?PluginAnimeData
    {
        // TODO: заполнение карточки через GraphQL API Shikimori.
        // Скелет — заполнение ещё не реализовано, наполнится следующим релизом плагина.
        return null;
    }

    /**
     * @return list<string>
     */
    public function getFillableFields(): array
    {
        // TODO: перечислить реально заполняемые поля PluginAnimeData, когда
        // findById() будет реализован.
        return [];
    }
}
